<?php

use App\Models\Family;
use App\Models\User;
use App\Queries\DashboardStatsQuery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Support\AdminFixtures;

beforeEach(function (): void {
    $this->travelTo(AdminFixtures::now());
    $this->user = User::factory()->lecteur()->create();
});

/**
 * @param  array<string, Family>  $families
 * @return array{family: array{id: int, name: string}, v1: int, v2: int, v3: int}
 */
function statsFamilyRow(array $families, string $name, int $v1 = 0, int $v2 = 0, int $v3 = 0): array
{
    return ['family' => ['id' => $families[$name]->id, 'name' => $name], 'v1' => $v1, 'v2' => $v2, 'v3' => $v3];
}

it('renvoie les statistiques exactes du contrat, sans enveloppe data', function (): void {
    $families = AdminFixtures::families();

    // Septembre 2026 = Force ; août = Sagesse ; mars = Honneur ; janvier = Sagesse ; février = Force.
    AdminFixtures::visitor(['2026-09-22']);                                          // prospect, inscrit aujourd'hui
    AdminFixtures::visitor(['2026-08-10', '2026-09-06']);                            // recurrent
    AdminFixtures::visitor(['2025-12-15', '2026-01-10', '2026-02-01']);              // membre potentiel
    AdminFixtures::visitor(['2026-03-01', '2026-03-08', '2026-03-15'], member: true); // membre
    AdminFixtures::visitor(['2026-09-01'], withFamily: false);                       // prospect, sans famille
    // Inscrit hier à 23 h 59 (Abidjan) : pas « nouveau aujourd'hui ».
    AdminFixtures::visitor([], ['created_at' => AdminFixtures::now()->subDay()->setTime(23, 59, 59)]);

    $months = [
        [2025, 10, 0], [2025, 11, 0], [2025, 12, 1], [2026, 1, 1], [2026, 2, 1], [2026, 3, 3],
        [2026, 4, 0], [2026, 5, 0], [2026, 6, 0], [2026, 7, 0], [2026, 8, 1], [2026, 9, 3],
    ];

    $this->actingAs($this->user)
        ->getJson('/api/admin/stats')
        ->assertOk()
        ->assertExactJson([
            'total_visitors' => 6,
            'new_today' => 1,
            'visits_this_month' => 3,
            'by_status' => ['prospect' => 3, 'recurrent' => 1, 'membre_potentiel' => 1, 'membre' => 1],
            'current_family' => ['id' => $families['Force']->id, 'name' => 'Force'],
            'next_family' => ['id' => $families['Honneur']->id, 'name' => 'Honneur'],
            'visits_by_family' => [
                statsFamilyRow($families, 'Puissance'),
                statsFamilyRow($families, 'Richesse'),
                statsFamilyRow($families, 'Sagesse', v1: 1, v2: 1),
                statsFamilyRow($families, 'Force', v1: 1, v2: 1, v3: 1),
                statsFamilyRow($families, 'Honneur', v1: 1, v2: 1, v3: 1),
                statsFamilyRow($families, 'Gloire'),
                statsFamilyRow($families, 'Louange'),
            ],
            'monthly_visits' => array_map(
                static fn (array $m): array => ['year' => $m[0], 'month' => $m[1], 'count' => $m[2]],
                $months,
            ),
        ]);
});

it('renvoie des zéros (4 statuts, 7 familles, 12 mois) sur une base vide', function (): void {
    AdminFixtures::families();

    $response = $this->actingAs($this->user)->getJson('/api/admin/stats')->assertOk();

    expect($response->json('total_visitors'))->toBe(0)
        ->and($response->json('new_today'))->toBe(0)
        ->and($response->json('visits_this_month'))->toBe(0)
        ->and($response->json('by_status'))->toBe(['prospect' => 0, 'recurrent' => 0, 'membre_potentiel' => 0, 'membre' => 0])
        ->and($response->json('visits_by_family'))->toHaveCount(7)
        ->and(collect($response->json('visits_by_family'))->sum(fn (array $row): int => $row['v1'] + $row['v2'] + $row['v3']))->toBe(0)
        ->and($response->json('monthly_visits'))->toHaveCount(12)
        ->and($response->json('monthly_visits.0'))->toBe(['year' => 2025, 'month' => 10, 'count' => 0])
        ->and($response->json('monthly_visits.11'))->toBe(['year' => 2026, 'month' => 9, 'count' => 0]);
});

it('renvoie des familles nulles sans rotation', function (): void {
    $this->actingAs($this->user)
        ->getJson('/api/admin/stats')
        ->assertOk()
        ->assertJsonPath('current_family', null)
        ->assertJsonPath('next_family', null)
        ->assertJsonPath('visits_by_family', []);
});

it('ignore les familles inactives sans visite mais garde celles qui ont accueilli', function (): void {
    $families = AdminFixtures::families();
    AdminFixtures::visitor(['2026-04-05']); // Gloire
    $families['Gloire']->update(['active' => false]);
    $families['Louange']->update(['active' => false]);

    $names = collect($this->actingAs($this->user)->getJson('/api/admin/stats')->json('visits_by_family'))
        ->pluck('family.name')
        ->all();

    expect($names)->toBe(['Puissance', 'Richesse', 'Sagesse', 'Force', 'Honneur', 'Gloire']);
});

it('franchit correctement le changement d\'année dans monthly_visits', function (): void {
    $this->travelTo(AdminFixtures::now()->setDate(2027, 2, 10));
    AdminFixtures::families();
    AdminFixtures::visitor(['2026-12-31', '2027-01-15']);

    $months = $this->actingAs($this->user)->getJson('/api/admin/stats')->json('monthly_visits');

    expect($months[0])->toBe(['year' => 2026, 'month' => 3, 'count' => 0])
        ->and($months[9])->toBe(['year' => 2026, 'month' => 12, 'count' => 1])
        ->and($months[10])->toBe(['year' => 2027, 'month' => 1, 'count' => 1])
        ->and($months[11])->toBe(['year' => 2027, 'month' => 2, 'count' => 0]);
});

it('utilise un nombre constant de requêtes agrégées, quel que soit le volume', function (): void {
    AdminFixtures::families();
    AdminFixtures::visitor(['2026-09-01']);

    $count = function (): int {
        app(DashboardStatsQuery::class)->forget();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->getJson('/api/admin/stats')->assertOk();
        DB::disableQueryLog();

        return count(array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_contains($query['query'], 'visit'),
        ));
    };

    $before = $count();
    AdminFixtures::bulkVisitors(50);
    AdminFixtures::visitor(['2026-08-01', '2026-09-05']);

    expect($count())->toBe($before)->and($before)->toBeLessThanOrEqual(8);
});

it('met les statistiques en cache 5 minutes', function (): void {
    AdminFixtures::families();
    AdminFixtures::visitor(['2026-09-01']);

    $this->actingAs($this->user)->getJson('/api/admin/stats')->assertJsonPath('total_visitors', 1);
    expect(Cache::has(DashboardStatsQuery::CACHE_KEY))->toBeTrue();

    AdminFixtures::visitor(['2026-09-02']);
    $this->getJson('/api/admin/stats')->assertJsonPath('total_visitors', 1);

    $this->travel(5)->minutes();
    $this->travel(1)->seconds();
    $this->getJson('/api/admin/stats')->assertJsonPath('total_visitors', 2);
});
