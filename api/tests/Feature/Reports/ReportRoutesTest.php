<?php

use App\Enums\Role;
use App\Models\FamilyRotation;
use App\Models\ReportDispatch;
use Carbon\CarbonImmutable;
use Database\Seeders\FamilySeeder;
use Tests\Feature\Reports\Support\ReportData;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-22 10:00', 'Africa/Abidjan'));
    $this->seed(FamilySeeder::class);
    $this->v = ReportData::scenario();
    $this->sagesse = ReportData::family('Sagesse');
    $this->force = ReportData::family('Force');
});

describe('GET /api/admin/reports', function (): void {
    it('liste les mois de l\'année en cours jusqu\'au mois courant, du plus récent au plus ancien', function (): void {
        $response = $this->actingAs(userWithRole(Role::Lecteur))
            ->getJson('/api/admin/reports?year=2026')
            ->assertOk()
            ->assertJsonCount(9, 'data')
            ->assertJsonPath('meta', ['available_years' => [2025, 2026]]);

        expect(array_column($response->json('data'), 'month'))->toBe([9, 8, 7, 6, 5, 4, 3, 2, 1]);

        expect($response->json('data.1'))->toBe([
            'year' => 2026,
            'month' => 8,
            'family' => ['id' => $this->sagesse->id, 'name' => 'Sagesse'],
            'counts' => ['v1' => 2, 'v2' => 1, 'v3' => 1, 'total' => 4, 'conversions' => 1],
            'dispatch' => null,
        ]);
    });

    it('renvoie les 12 mois d\'une année passée', function (): void {
        $response = $this->actingAs(userWithRole(Role::Lecteur))
            ->getJson('/api/admin/reports?year=2025')
            ->assertOk()
            ->assertJsonCount(12, 'data');

        expect(array_column($response->json('data'), 'month'))->toBe(range(12, 1))
            ->and($response->json('data.11.family'))->toBeNull();
    });

    it('utilise l\'année courante par défaut', function (): void {
        $this->actingAs(userWithRole(Role::Lecteur))
            ->getJson('/api/admin/reports')
            ->assertOk()
            ->assertJsonCount(9, 'data')
            ->assertJsonPath('data.0.year', 2026);
    });

    it('expose l\'envoi du mois (sent_at ISO 8601, destinataires)', function (): void {
        ReportDispatch::factory()->forMonth(2026, 8)->create([
            'family_id' => $this->sagesse->id,
            'sent_at' => CarbonImmutable::parse('2026-09-01 08:00:05', 'Africa/Abidjan'),
            'recipients' => ['pasteur@exemple.test', 'sagesse@exemple.test'],
        ]);

        $this->actingAs(userWithRole(Role::Lecteur))
            ->getJson('/api/admin/reports?year=2026')
            ->assertOk()
            ->assertJsonPath('data.1.dispatch', [
                'sent_at' => '2026-09-01T08:00:05+00:00',
                'recipients' => ['pasteur@exemple.test', 'sagesse@exemple.test'],
            ])
            ->assertJsonPath('data.0.dispatch', null);
    });

    it('refuse une année hors des années disponibles (422)', function (string $year): void {
        $this->actingAs(userWithRole(Role::Lecteur))
            ->getJson("/api/admin/reports?year={$year}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation')
            ->assertJsonValidationErrors(['year']);
    })->with(['2024', '2027', 'abc', '2026.5']);
});

describe('GET /api/admin/reports/{year}/{month}', function (): void {
    it('renvoie le détail du mois au format du contrat', function (): void {
        $this->actingAs(userWithRole(Role::Lecteur))
            ->getJson('/api/admin/reports/2026/8')
            ->assertOk()
            ->assertExactJson(['data' => [
                'year' => 2026,
                'month' => 8,
                'family' => ['id' => $this->sagesse->id, 'name' => 'Sagesse'],
                'counts' => ['v1' => 2, 'v2' => 1, 'v3' => 1, 'total' => 4, 'conversions' => 1],
                'dispatch' => null,
                'visitors' => [
                    ['id' => $this->v['awa']->id, 'full_name' => 'Awa Koné', 'phone' => '+2250700000001', 'visit_number' => 1, 'visit_date' => '2026-08-02'],
                    ['id' => $this->v['bakary']->id, 'full_name' => 'Bakary Traoré', 'phone' => '+2250700000002', 'visit_number' => 2, 'visit_date' => '2026-08-09'],
                    ['id' => $this->v['chantal']->id, 'full_name' => 'Chantal Yao', 'phone' => '+2250700000003', 'visit_number' => 3, 'visit_date' => '2026-08-16'],
                    ['id' => $this->v['gisele']->id, 'full_name' => 'Gisèle N\'Guessan', 'phone' => '+2250700000007', 'visit_number' => 1, 'visit_date' => '2026-08-31'],
                ],
                'conversions' => [
                    ['id' => $this->v['moussa']->id, 'full_name' => 'Moussa Cissé', 'converted_at' => '2026-08-20T11:00:00+00:00'],
                ],
            ]]);
    });

    it('accepte le mois en cours (rapport provisoire)', function (): void {
        $this->actingAs(userWithRole(Role::Lecteur))
            ->getJson('/api/admin/reports/2026/9')
            ->assertOk()
            ->assertJsonPath('data.family.name', 'Force')
            ->assertJsonPath('data.counts.total', 2)
            ->assertJsonCount(2, 'data.visitors');
    });

    it('accepte un mois zéro-préfixé', function (): void {
        $this->actingAs(userWithRole(Role::Lecteur))
            ->getJson('/api/admin/reports/2026/08')
            ->assertOk()
            ->assertJsonPath('data.month', 8);
    });

    it('renvoie family = null et des compteurs à 0 pour un mois sans rotation', function (): void {
        FamilyRotation::query()->where('year', 2026)->where('month', 8)->delete();

        $this->actingAs(userWithRole(Role::Lecteur))
            ->getJson('/api/admin/reports/2026/8')
            ->assertOk()
            ->assertJsonPath('data.family', null)
            ->assertJsonPath('data.counts', ['v1' => 0, 'v2' => 0, 'v3' => 0, 'total' => 0, 'conversions' => 0])
            ->assertJsonPath('data.visitors', [])
            ->assertJsonPath('data.conversions', []);
    });

    it('répond 404 pour un mois futur, invalide ou hors des années disponibles', function (string $path): void {
        $this->actingAs(userWithRole(Role::SuperAdmin))
            ->getJson("/api/admin/reports/{$path}")
            ->assertNotFound()
            ->assertExactJson(['message' => 'Ressource introuvable.', 'code' => 'not_found']);
    })->with([
        'mois futur' => '2026/10',
        'année future' => '2027/1',
        'mois 13' => '2026/13',
        'mois 0' => '2026/0',
        'avant la 1re visite' => '2024/12',
        'année non numérique' => 'abcd/1',
        'mois non numérique' => '2026/aa',
        'année sur 2 chiffres' => '26/8',
    ]);
});

describe('permissions de lecture', function (): void {
    it('autorise la lecture à tous les rôles', function (Role $role): void {
        $this->actingAs(userWithRole($role))->getJson('/api/admin/reports')->assertOk();
        $this->actingAs(userWithRole($role))->getJson('/api/admin/reports/2026/8')->assertOk();
    })->with([Role::Lecteur, Role::Moderateur, Role::SuperAdmin]);

    it('refuse un invité (401)', function (): void {
        $this->getJson('/api/admin/reports')->assertUnauthorized();
        $this->getJson('/api/admin/reports/2026/8')->assertUnauthorized();
    });
});
