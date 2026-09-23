<?php

use App\Models\AuditLog;
use App\Models\FamilyRotation;
use App\Models\User;
use App\Queries\DashboardStatsQuery;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Admin\Support\AdminFixtures;

beforeEach(function (): void {
    $this->travelTo(AdminFixtures::now());
    $this->families = AdminFixtures::families();
    $this->ref = fn (string $name): array => ['id' => $this->families[$name]->id, 'name' => $name];
});

describe('GET /families', function (): void {
    it('liste les familles dans l\'ordre de rotation', function (): void {
        $this->families['Gloire']->update(['active' => false]);

        $response = $this->actingAs(User::factory()->lecteur()->create())->getJson('/api/admin/families')->assertOk();

        expect($response->json('data'))->toHaveCount(7)
            ->and($response->json('data.0'))->toBe(['id' => $this->families['Puissance']->id, 'name' => 'Puissance', 'active' => true, 'position' => 1])
            ->and($response->json('data.5'))->toBe(['id' => $this->families['Gloire']->id, 'name' => 'Gloire', 'active' => false, 'position' => 6])
            ->and(array_column($response->json('data'), 'name'))->toBe(['Puissance', 'Richesse', 'Sagesse', 'Force', 'Honneur', 'Gloire', 'Louange']);
    });
});

describe('GET /rotations', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->lecteur()->create());
    });

    it('renvoie tous les mois de la plage demandée', function (): void {
        $this->getJson('/api/admin/rotations?from=2026-01&months=3')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['year' => 2026, 'month' => 1, 'family' => ($this->ref)('Sagesse')],
                ['year' => 2026, 'month' => 2, 'family' => ($this->ref)('Force')],
                ['year' => 2026, 'month' => 3, 'family' => ($this->ref)('Honneur')],
            ]]);
    });

    it('renvoie family = null pour un mois sans rotation et franchit les années', function (): void {
        $this->getJson('/api/admin/rotations?from=2025-09&months=4')
            ->assertExactJson(['data' => [
                ['year' => 2025, 'month' => 9, 'family' => null],
                ['year' => 2025, 'month' => 10, 'family' => null],
                ['year' => 2025, 'month' => 11, 'family' => ($this->ref)('Puissance')],
                ['year' => 2025, 'month' => 12, 'family' => ($this->ref)('Richesse')],
            ]]);

        $data = $this->getJson('/api/admin/rotations?from=2027-11&months=3')->json('data');

        // Novembre 2027 = 24 mois après l'ancre (Puissance) : 24 mod 7 = 3 => Force.
        expect($data)->toBe([
            ['year' => 2027, 'month' => 11, 'family' => ($this->ref)('Force')],
            ['year' => 2027, 'month' => 12, 'family' => ($this->ref)('Honneur')],
            ['year' => 2028, 'month' => 1, 'family' => null],
        ]);
    });

    it('part par défaut du mois courant sur 12 mois', function (): void {
        $data = $this->getJson('/api/admin/rotations')->assertOk()->json('data');

        expect($data)->toHaveCount(12)
            ->and($data[0])->toBe(['year' => 2026, 'month' => 9, 'family' => ($this->ref)('Force')])
            ->and($data[11])->toBe(['year' => 2027, 'month' => 8, 'family' => ($this->ref)('Puissance')]);
    });

    it('accepte jusqu\'à 36 mois', function (): void {
        $this->getJson('/api/admin/rotations?from=2026-01&months=36')->assertOk()->assertJsonCount(36, 'data');
    });

    it('rejette des paramètres invalides (422)', function (string $query, string $field): void {
        $this->getJson('/api/admin/rotations?'.$query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    })->with([
        'months = 0' => ['months=0', 'months'],
        'months = 37' => ['months=37', 'months'],
        'months non numérique' => ['months=abc', 'months'],
        'mois 13' => ['from=2026-13', 'from'],
        'mois 00' => ['from=2026-00', 'from'],
        'format jour' => ['from=2026-01-01', 'from'],
        'texte' => ['from=janvier', 'from'],
    ]);
});

describe('PUT /rotations/{year}/{month}', function (): void {
    beforeEach(function (): void {
        $this->admin = User::factory()->superAdmin()->create();
        $this->actingAs($this->admin);
    });

    it('modifie la famille d\'un mois, journalise et invalide les caches', function (): void {
        Cache::put('public:config', ['current_family' => 'périmé'], 60);
        Cache::put(DashboardStatsQuery::CACHE_KEY, ['total_visitors' => 99], 300);

        $this->putJson('/api/admin/rotations/2026/10', ['family_id' => $this->families['Gloire']->id])
            ->assertOk()
            ->assertExactJson(['data' => ['year' => 2026, 'month' => 10, 'family' => ($this->ref)('Gloire')]]);

        $rotation = FamilyRotation::query()->where(['year' => 2026, 'month' => 10])->sole();
        $log = AuditLog::query()->sole();

        expect($rotation->family_id)->toBe($this->families['Gloire']->id)
            ->and($log->action)->toBe('rotation.updated')
            ->and($log->subject_type)->toBe('rotation')
            ->and($log->subject_id)->toBe($rotation->id)
            ->and($log->user_id)->toBe($this->admin->id)
            ->and($log->meta)->toBe([
                'year' => 2026, 'month' => 10,
                'family_id' => $this->families['Gloire']->id,
                'previous_family_id' => $this->families['Honneur']->id,
            ])
            ->and(Cache::has('public:config'))->toBeFalse()
            ->and(Cache::has(DashboardStatsQuery::CACHE_KEY))->toBeFalse();

        $this->getJson('/api/admin/rotations?from=2026-10&months=1')->assertJsonPath('data.0.family.name', 'Gloire');
    });

    it('crée la rotation d\'un mois qui n\'en avait pas', function (): void {
        $this->putJson('/api/admin/rotations/2030/1', ['family_id' => $this->families['Force']->id])
            ->assertOk()
            ->assertJsonPath('data', ['year' => 2030, 'month' => 1, 'family' => ($this->ref)('Force')]);

        expect(FamilyRotation::query()->where(['year' => 2030, 'month' => 1])->value('family_id'))->toBe($this->families['Force']->id)
            ->and(AuditLog::query()->sole()->meta['previous_family_id'])->toBeNull();
    });

    it('accepte une famille inactive et le mois sur deux chiffres', function (): void {
        $this->families['Louange']->update(['active' => false]);

        $this->putJson('/api/admin/rotations/2026/09', ['family_id' => $this->families['Louange']->id])
            ->assertOk()
            ->assertJsonPath('data.month', 9)
            ->assertJsonPath('data.family.name', 'Louange');
    });

    it('ne journalise pas une rotation inchangée', function (): void {
        $this->putJson('/api/admin/rotations/2026/9', ['family_id' => $this->families['Force']->id])->assertOk();

        expect(AuditLog::query()->count())->toBe(0);
    });

    it('valide l\'année, le mois et la famille (422)', function (string $uri, array $payload, string $field): void {
        $this->putJson('/api/admin/rotations/'.$uri, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        expect(AuditLog::query()->count())->toBe(0);
    })->with([
        'mois 13' => ['2026/13', ['family_id' => 1], 'month'],
        'mois 0' => ['2026/0', ['family_id' => 1], 'month'],
        'année 1999' => ['1999/5', ['family_id' => 1], 'year'],
        'famille manquante' => ['2026/10', [], 'family_id'],
        'famille inexistante' => ['2026/10', ['family_id' => 999999], 'family_id'],
        'famille non numérique' => ['2026/10', ['family_id' => 'Force'], 'family_id'],
    ]);

    it('répond 404 pour une URL hors format', function (string $uri): void {
        $this->putJson('/api/admin/rotations/'.$uri, ['family_id' => $this->families['Force']->id])->assertNotFound();
    })->with(['26/10', '2026/100', 'abcd/10']);
});
