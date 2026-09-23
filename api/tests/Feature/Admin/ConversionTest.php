<?php

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\User;
use App\Queries\DashboardStatsQuery;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Admin\Support\AdminFixtures;

beforeEach(function (): void {
    $this->travelTo(AdminFixtures::now());
    AdminFixtures::families();
    $this->admin = User::factory()->superAdmin()->create(['name' => 'Jean Admin']);
    $this->actingAs($this->admin);
});

it('convertit un membre potentiel en membre', function (): void {
    $visitor = AdminFixtures::visitor(['2026-07-01', '2026-08-02', '2026-09-03']);
    Cache::put(DashboardStatsQuery::CACHE_KEY, ['total_visitors' => 99], 300);

    $this->postJson("/api/admin/visitors/{$visitor->id}/convert")
        ->assertOk()
        ->assertJsonPath('data.id', $visitor->id)
        ->assertJsonPath('data.status', 'membre')
        ->assertJsonPath('data.member', [
            'converted_at' => '2026-09-22T10:00:00+00:00',
            'converted_by' => ['id' => $this->admin->id, 'name' => 'Jean Admin'],
        ]);

    $member = Member::query()->sole();
    $log = AuditLog::query()->sole();

    expect($member->visitor_id)->toBe($visitor->id)
        ->and($member->converted_by)->toBe($this->admin->id)
        ->and($visitor->fresh()->status->value)->toBe('membre')
        ->and($log->action)->toBe('visitor.converted')
        ->and($log->subject_type)->toBe('visitor')
        ->and($log->subject_id)->toBe($visitor->id)
        ->and($log->meta)->toBe(['member_id' => $member->id])
        ->and(Cache::has(DashboardStatsQuery::CACHE_KEY))->toBeFalse();
});

it('refuse un visiteur non éligible (409 not_eligible)', function (array $dates): void {
    $visitor = AdminFixtures::visitor($dates);

    $this->postJson("/api/admin/visitors/{$visitor->id}/convert")
        ->assertStatus(409)
        ->assertJsonPath('code', 'not_eligible');

    expect(Member::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and($visitor->fresh()->status->value)->toBe($visitor->status->value);
})->with([
    'sans visite' => [[]],
    'prospect' => [['2026-09-01']],
    'récurrent' => [['2026-08-01', '2026-09-01']],
]);

it('refuse un visiteur déjà membre (409 already_member)', function (): void {
    $visitor = AdminFixtures::visitor(['2026-07-01', '2026-08-02', '2026-09-03'], member: true);

    $this->postJson("/api/admin/visitors/{$visitor->id}/convert")
        ->assertStatus(409)
        ->assertJsonPath('code', 'already_member');

    expect(Member::query()->count())->toBe(1)->and(AuditLog::query()->count())->toBe(0);
});

it('refuse une double conversion successive', function (): void {
    $visitor = AdminFixtures::visitor(['2026-07-01', '2026-08-02', '2026-09-03']);

    $this->postJson("/api/admin/visitors/{$visitor->id}/convert")->assertOk();
    $this->postJson("/api/admin/visitors/{$visitor->id}/convert")->assertStatus(409)->assertJsonPath('code', 'already_member');

    expect(Member::query()->count())->toBe(1);
});

it('annule une conversion et recalcule le statut', function (): void {
    $visitor = AdminFixtures::visitor(['2026-07-01', '2026-08-02', '2026-09-03'], member: true);
    Cache::put(DashboardStatsQuery::CACHE_KEY, ['total_visitors' => 99], 300);

    $this->deleteJson("/api/admin/visitors/{$visitor->id}/convert")
        ->assertOk()
        ->assertJsonPath('data.status', 'membre_potentiel')
        ->assertJsonPath('data.member', null);

    $log = AuditLog::query()->sole();

    expect(Member::query()->count())->toBe(0)
        ->and($visitor->fresh()->status->value)->toBe('membre_potentiel')
        ->and($log->action)->toBe('visitor.unconverted')
        ->and($log->subject_id)->toBe($visitor->id)
        ->and($log->meta)->toBe(['status' => 'membre_potentiel'])
        ->and(Cache::has(DashboardStatsQuery::CACHE_KEY))->toBeFalse();
});

it('refuse d\'annuler la conversion d\'un non-membre (409 not_member)', function (): void {
    $visitor = AdminFixtures::visitor(['2026-07-01', '2026-08-02', '2026-09-03']);

    $this->deleteJson("/api/admin/visitors/{$visitor->id}/convert")
        ->assertStatus(409)
        ->assertJsonPath('code', 'not_member');

    expect(AuditLog::query()->count())->toBe(0);
});

it('répond 404 pour un visiteur inexistant', function (string $method): void {
    $this->json($method, '/api/admin/visitors/999999/convert')->assertNotFound()->assertJsonPath('code', 'not_found');
})->with(['POST', 'DELETE']);
