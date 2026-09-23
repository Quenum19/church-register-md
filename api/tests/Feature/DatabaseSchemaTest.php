<?php

use App\Models\AuditLog;
use App\Models\Family;
use App\Models\Member;
use App\Models\ReportDispatch;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorNote;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('utilise le moteur InnoDB pour toutes les tables', function (): void {
    $engines = collect(DB::select('SELECT table_name AS name, engine FROM information_schema.tables WHERE table_schema = DATABASE()'))
        ->reject(fn (object $table): bool => $table->engine === 'InnoDB');

    expect($engines->all())->toBe([]);
});

it('interdit deux visites le même jour ou avec le même numéro', function (): void {
    $visitor = Visitor::factory()->prospect(now()->subWeek())->create();
    $first = $visitor->visits()->firstOrFail();

    expect(fn () => Visit::factory()->second()->for($visitor)->onDate($first->visit_date)->create())
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => Visit::factory()->first()->for($visitor)->onDate(now())->create())
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => Visit::factory()->for($visitor)->onDate(now())->create(['visit_number' => 4]))
        ->toThrow(QueryException::class);
});

it('rend unique la clé d\'idempotence et le numéro de téléphone', function (): void {
    $visit = Visit::factory()->create();

    expect(fn () => Visit::factory()->create(['idempotency_key' => $visit->idempotency_key]))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => Visitor::factory()->create(['phone' => $visit->visitor->phone]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('stocke les réponses de la 1re visite sous forme d\'objet JSON', function (): void {
    $visit = Visit::factory()->first()->create();

    expect(DB::table('visits')->where('id', $visit->id)->value('answers'))->toBe('{}')
        ->and($visit->fresh()?->answers)->toBe([]);
});

it('supprime visites, notes et conversion avec le visiteur', function (): void {
    $visitor = Visitor::factory()->member()->create();
    VisitorNote::factory()->for($visitor)->create();

    $visitor->delete();

    expect(Visit::query()->count())->toBe(0)
        ->and(VisitorNote::query()->count())->toBe(0)
        ->and(Member::query()->count())->toBe(0);
});

it('conserve notes, journal et conversions quand un compte est supprimé', function (): void {
    $user = User::factory()->superAdmin()->create();
    $visitor = Visitor::factory()->member($user)->create();
    $note = VisitorNote::factory()->for($visitor)->create(['user_id' => $user->id]);
    $log = app(AuditLogger::class)->log('visitor.converted', $visitor, [], $user);
    $dispatch = ReportDispatch::factory()->create(['sent_by' => $user->id]);

    $user->delete();

    expect($note->fresh()?->user_id)->toBeNull()
        ->and($log->fresh()?->user_id)->toBeNull()
        ->and(Member::query()->sole()->converted_by)->toBeNull()
        ->and($dispatch->fresh()?->sent_by)->toBeNull();
});

it('garde la visite si sa famille est supprimée, mais protège les rotations', function (): void {
    $family = Family::factory()->create();
    $visit = Visit::factory()->create(['family_id' => $family->id]);

    $family->delete();

    expect($visit->fresh()?->family_id)->toBeNull();

    $serving = Family::factory()->create();
    $serving->rotations()->create(['year' => 2040, 'month' => 1]);

    expect(fn () => $serving->delete())->toThrow(QueryException::class);
});

it('rend unique un envoi de rapport par mois', function (): void {
    ReportDispatch::factory()->forMonth(2026, 8)->create();

    expect(fn () => ReportDispatch::factory()->forMonth(2026, 8)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('chiffre les secrets 2FA en base', function (): void {
    $user = User::factory()->withTwoFactor('JBSWY3DPEHPK3PXP')->create();
    $raw = DB::table('users')->where('id', $user->id)->first(['two_factor_secret', 'two_factor_recovery_codes']);

    expect($raw->two_factor_secret)->not->toContain('JBSWY3DPEHPK3PXP')
        ->and($user->fresh()?->two_factor_secret)->toBe('JBSWY3DPEHPK3PXP')
        ->and($user->fresh()?->two_factor_recovery_codes)->toHaveCount(8)
        ->and($user->hasTwoFactorEnabled())->toBeTrue();
});

it('masque les champs sensibles de l\'utilisateur à la sérialisation', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    expect($user->toArray())->not->toHaveKeys(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes']);
});

it('distingue une invitation en attente', function (): void {
    expect(User::factory()->invited()->create()->isInvitationPending())->toBeTrue()
        ->and(User::factory()->create()->isInvitationPending())->toBeFalse()
        ->and(User::factory()->locked()->create()->isLocked())->toBeTrue();
});

it('enregistre les sujets du journal avec un alias stable', function (): void {
    $visitor = Visitor::factory()->create();
    $log = app(AuditLogger::class)->log('visitor.updated', $visitor);

    expect($log->subject_type)->toBe('visitor')
        ->and(AuditLog::query()->with('subject')->findOrFail($log->id)->subject?->is($visitor))->toBeTrue();
});
