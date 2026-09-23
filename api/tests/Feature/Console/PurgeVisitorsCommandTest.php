<?php

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorNote;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-22 03:00', 'Africa/Abidjan'));

    // Dernière visite il y a plus de 24 mois : à purger.
    $this->old = Visitor::factory()->recurrent(CarbonImmutable::parse('2024-06-02'))->create();
    VisitorNote::factory()->for($this->old)->create();

    // Membre ancien : conservé.
    $this->oldMember = Visitor::factory()->member(null, CarbonImmutable::parse('2024-01-07'))->create();

    // 1re visite ancienne mais dernière visite récente : conservé.
    $this->returning = Visitor::factory()->create();
    Visit::factory()->first()->for($this->returning)->onDate('2024-05-05')->create();
    Visit::factory()->second()->for($this->returning)->onDate('2026-03-01')->create();
    $this->returning->forceFill(['created_at' => '2024-05-05 10:00:00'])->saveQuietly();

    // Visite récente : conservé.
    $this->recent = Visitor::factory()->prospect(CarbonImmutable::parse('2026-09-20'))->create();
});

it('ne supprime rien en mode simulation', function (): void {
    $this->artisan('visitors:purge', ['--dry-run' => true])
        ->expectsOutputToContain('1 visiteur(s) seraient supprimé(s)')
        ->assertSuccessful();

    expect(Visitor::query()->count())->toBe(4)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('supprime en cascade les non-membres sans visite depuis 24 mois et journalise', function (): void {
    $this->artisan('visitors:purge')
        ->expectsOutputToContain('1 visiteur(s) supprimé(s)')
        ->assertSuccessful();

    expect(Visitor::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->oldMember->id, $this->returning->id, $this->recent->id])->sort()->values()->all())
        ->and(Visit::query()->where('visitor_id', $this->old->id)->exists())->toBeFalse()
        ->and(VisitorNote::query()->where('visitor_id', $this->old->id)->exists())->toBeFalse()
        ->and(Member::query()->count())->toBe(1);

    $log = AuditLog::query()->where('action', 'visitors.purged')->sole();

    expect($log->meta)->toMatchArray(['count' => 1, 'cutoff' => '2024-09-22', 'retention_months' => 24])
        ->and($log->user_id)->toBeNull();
});

it('est planifiée chaque jour à 03:00', function (): void {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('visitors:purge')
        ->assertSuccessful();

    $events = collect(app(Schedule::class)->events())
        ->mapWithKeys(fn ($event) => [(string) $event->command => $event->expression]);

    $expression = fn (string $needle): ?string => $events->first(fn ($expr, $command) => str_contains($command, $needle));

    expect($expression('visitors:purge'))->toBe('0 3 * * *')
        ->and($expression('rotations:extend'))->toBe('0 1 1 * *')
        ->and($expression('queue:work --stop-when-empty --max-time=50'))->toBe('* * * * *');
});

it('ne journalise rien quand il n\'y a rien à purger', function (): void {
    $this->artisan('visitors:purge')->assertSuccessful();
    expect(AuditLog::query()->where('action', 'visitors.purged')->count())->toBe(1);

    AuditLog::query()->delete();

    // Deuxième passage : plus rien à supprimer, donc AUCUNE ligne de journal (sinon la tâche
    // quotidienne ajoute du bruit à vie et masque les vraies purges).
    $this->artisan('visitors:purge')
        ->expectsOutputToContain('0 visiteur(s) supprimé(s)')
        ->expectsOutputToContain("0 entrée(s) du journal d'audit supprimée(s)")
        ->assertSuccessful();

    expect(AuditLog::query()->count())->toBe(0);
});

it('purge le journal d\'audit de plus de 24 mois', function (): void {
    $old = AuditLog::factory()->create();
    $old->forceFill(['created_at' => '2024-09-21 10:00:00'])->save();

    $limit = AuditLog::factory()->create();
    $limit->forceFill(['created_at' => '2024-09-23 10:00:00'])->save();

    $this->artisan('visitors:purge')
        ->expectsOutputToContain("1 entrée(s) du journal d'audit supprimée(s) (avant le 2024-09-22).")
        ->assertSuccessful();

    expect(AuditLog::query()->find($old->id))->toBeNull()
        ->and(AuditLog::query()->find($limit->id))->not->toBeNull();

    $log = AuditLog::query()->where('action', 'audit_logs.purged')->sole();
    expect($log->meta)->toMatchArray(['count' => 1, 'cutoff' => '2024-09-22', 'retention_months' => 24])
        ->and($log->user_id)->toBeNull();
});

it('annonce les deux purges en simulation, sans rien supprimer ni journaliser', function (): void {
    $old = AuditLog::factory()->create();
    $old->forceFill(['created_at' => '2024-01-01 10:00:00'])->save();

    $this->artisan('visitors:purge', ['--dry-run' => true])
        ->expectsOutputToContain('1 visiteur(s) seraient supprimé(s)')
        ->expectsOutputToContain("1 entrée(s) du journal d'audit seraient supprimée(s)")
        ->assertSuccessful();

    expect(Visitor::query()->count())->toBe(4)
        ->and(AuditLog::query()->count())->toBe(1);
});
