<?php

use App\Models\User;
use App\Models\Visitor;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Support\AdminFixtures;

beforeEach(function (): void {
    $this->travelTo(AdminFixtures::now());
    $this->families = AdminFixtures::families();
    $this->actingAs(User::factory()->lecteur()->create());

    $this->ids = fn (string $query = ''): array => collect(
        $this->getJson('/api/admin/visitors'.($query !== '' ? '?'.$query : ''))->assertOk()->json('data'),
    )->pluck('id')->all();
});

it('renvoie des VisitorSummary exactement au format du contrat', function (): void {
    $visitor = AdminFixtures::visitor(['2026-08-02', '2026-09-06'], [
        'full_name' => 'Awa Koné',
        'phone' => '+2250700000001',
        'whatsapp' => '+2250500000001',
        'commune' => 'Cocody',
        'quartier' => 'Riviera 2',
    ]);

    $this->getJson('/api/admin/visitors')
        ->assertOk()
        ->assertExactJson([
            'data' => [[
                'id' => $visitor->id,
                'full_name' => 'Awa Koné',
                'phone' => '+2250700000001',
                'whatsapp' => '+2250500000001',
                'commune' => 'Cocody',
                'quartier' => 'Riviera 2',
                'status' => 'recurrent',
                'visit_count' => 2,
                'first_visit_date' => '2026-08-02',
                'last_visit_date' => '2026-09-06',
                'created_at' => '2026-08-02T10:00:00+00:00',
            ]],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1],
        ]);
});

it('renvoie visit_count 0 et des dates nulles pour un visiteur sans visite', function (): void {
    AdminFixtures::visitor([]);

    $this->getJson('/api/admin/visitors')
        ->assertJsonPath('data.0.visit_count', 0)
        ->assertJsonPath('data.0.first_visit_date', null)
        ->assertJsonPath('data.0.last_visit_date', null);
});

it('renvoie last_page = 1 et une liste vide sans résultat', function (): void {
    $this->getJson('/api/admin/visitors')
        ->assertOk()
        ->assertExactJson(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 0]]);

    AdminFixtures::visitor(['2026-09-01']);

    $this->getJson('/api/admin/visitors?search=introuvable')
        ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 0]);
});

it('pagine avec per_page et page', function (): void {
    foreach (range(1, 5) as $day) {
        AdminFixtures::visitor([sprintf('2026-09-%02d', $day)]);
    }

    $this->getJson('/api/admin/visitors?per_page=2&page=3')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta', ['current_page' => 3, 'last_page' => 3, 'per_page' => 2, 'total' => 5]);
});

it('rejette les paramètres invalides (422)', function (string $query, string $field): void {
    $this->getJson('/api/admin/visitors?'.$query)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation')
        ->assertJsonValidationErrors([$field]);
})->with([
    'per_page = 0' => ['per_page=0', 'per_page'],
    'per_page = 101' => ['per_page=101', 'per_page'],
    'page = 0' => ['page=0', 'page'],
    'recherche > 100 caractères' => ['search='.str_repeat('a', 101), 'search'],
    'recherche en tableau' => ['search[]=a', 'search'],
    'statut inconnu' => ['status=vip', 'status'],
    'famille non numérique' => ['family_id=abc', 'family_id'],
    'famille inexistante' => ['family_id=999999', 'family_id'],
    'date de début invalide' => ['from=2026-13-01', 'from'],
    'date de début au mauvais format' => ['from=01/09/2026', 'from'],
    'date de fin avant le début' => ['from=2026-09-10&to=2026-09-01', 'to'],
    'tri hors liste blanche' => ['sort=phone', 'sort'],
]);

it('accepte une recherche de 100 caractères', function (): void {
    $this->getJson('/api/admin/visitors?search='.str_repeat('a', 100))->assertOk();
});

describe('recherche', function (): void {
    beforeEach(function (): void {
        $this->awa = AdminFixtures::visitor(['2026-09-01'], [
            'full_name' => 'Awa Koné', 'phone' => '+2250700000001', 'commune' => 'Cocody', 'quartier' => 'Angré',
        ]);
        $this->yao = AdminFixtures::visitor(['2026-09-02'], [
            'full_name' => 'Yao Kouassi', 'phone' => '+2250102030405', 'commune' => 'Yopougon', 'quartier' => 'Niangon',
        ]);
        $this->special = AdminFixtures::visitor(['2026-09-03'], [
            'full_name' => 'Marc 100% (Dupont) \\ test_x', 'phone' => '+2250506070809', 'commune' => 'Plateau', 'quartier' => 'Indénié',
        ]);
    });

    it('cherche dans le nom, la commune et le quartier, sans tenir compte de la casse', function (string $search, string $expected): void {
        expect(($this->ids)('search='.urlencode($search)))->toBe([$this->{$expected}->id]);
    })->with([
        'nom' => ['kouassi', 'yao'],
        'nom partiel accentué' => ['koné', 'awa'],
        'commune' => ['YOPOUGON', 'yao'],
        'quartier' => ['angré', 'awa'],
    ]);

    it('cherche dans le téléphone par ses chiffres', function (string $search, string $expected): void {
        expect(($this->ids)('search='.urlencode($search)))->toBe([$this->{$expected}->id]);
    })->with([
        'format international avec espaces' => ['+225 07 00', 'awa'],
        'format local' => ['07 00 00 00 01', 'awa'],
        'avec tirets' => ['01-02-03', 'yao'],
        'E.164 complet' => ['+2250506070809', 'special'],
    ]);

    it('traite les caractères spéciaux comme du texte littéral', function (string $search, array $expected): void {
        $response = $this->getJson('/api/admin/visitors?search='.urlencode($search))->assertOk();

        expect(collect($response->json('data'))->pluck('id')->all())
            ->toBe(array_map(fn (string $name): int => $this->{$name}->id, $expected));
    })->with([
        'pourcent seul' => ['%', ['special']],
        'pourcent littéral' => ['100%', ['special']],
        'souligné' => ['_', ['special']],
        'parenthèse ouvrante' => ['(', ['special']],
        'parenthèses' => ['(Dupont)', ['special']],
        'antislash' => ['\\', ['special']],
        'antislash suivi d\'un joker' => ['\\%', []],
        'crochets de regex' => ['[a-z]+', []],
        'plus seul' => ['+', []],
        'apostrophe' => ["N'Guessan'", []],
        'guillemets SQL' => ['" OR 1=1 --', []],
    ]);

    it('ignore une recherche vide ou faite d\'espaces', function (): void {
        expect(($this->ids)('search=%20%20'))->toHaveCount(3);
    });
});

it('filtre par statut, y compris non_membre', function (): void {
    $prospect = AdminFixtures::visitor(['2026-09-01']);
    $recurrent = AdminFixtures::visitor(['2026-08-01', '2026-09-02']);
    $potential = AdminFixtures::visitor(['2026-07-01', '2026-08-02', '2026-09-03']);
    $member = AdminFixtures::visitor(['2026-06-01', '2026-06-08', '2026-06-15'], member: true);

    expect(($this->ids)('status=prospect'))->toBe([$prospect->id])
        ->and(($this->ids)('status=recurrent'))->toBe([$recurrent->id])
        ->and(($this->ids)('status=membre_potentiel'))->toBe([$potential->id])
        ->and(($this->ids)('status=membre'))->toBe([$member->id])
        ->and(($this->ids)('status=non_membre&sort=created_at'))->toBe([$potential->id, $recurrent->id, $prospect->id]);
});

it('filtre par famille : au moins une visite accueillie par cette famille', function (): void {
    // Septembre = Force, août = Sagesse, juillet = Richesse.
    $forceFirst = AdminFixtures::visitor(['2026-09-01']);
    $forceSecond = AdminFixtures::visitor(['2026-08-01', '2026-09-06']);
    $sagesseOnly = AdminFixtures::visitor(['2026-08-10']);
    AdminFixtures::visitor(['2026-07-05']);

    expect(($this->ids)('family_id='.$this->families['Force']->id.'&sort=created_at'))->toBe([$forceSecond->id, $forceFirst->id])
        ->and(($this->ids)('family_id='.$this->families['Sagesse']->id.'&sort=created_at'))->toBe([$forceSecond->id, $sagesseOnly->id])
        ->and(($this->ids)('family_id='.$this->families['Gloire']->id))->toBe([]);
});

it('filtre sur la date de 1re visite (bornes incluses)', function (): void {
    $august = AdminFixtures::visitor(['2026-08-31', '2026-09-06']);
    $first = AdminFixtures::visitor(['2026-09-01']);
    $last = AdminFixtures::visitor(['2026-09-15']);
    AdminFixtures::visitor(['2026-09-16']);

    expect(($this->ids)('from=2026-09-01&to=2026-09-15&sort=created_at'))->toBe([$first->id, $last->id])
        ->and(($this->ids)('to=2026-08-31'))->toBe([$august->id])
        ->and(($this->ids)('from=2026-09-16&to=2026-09-16'))->toHaveCount(1);
});

it('combine les filtres en ET (famille ET recherche ET statut ET période)', function (): void {
    $match = AdminFixtures::visitor(['2026-08-01', '2026-09-06'], ['full_name' => 'Awa Koné']);
    AdminFixtures::visitor(['2026-09-01'], ['full_name' => 'Awa Traoré']);             // Force mais prospect
    AdminFixtures::visitor(['2026-08-02', '2026-09-07'], ['full_name' => 'Yao Koné']); // Force, recurrent, autre nom
    AdminFixtures::visitor(['2026-07-01', '2026-07-20'], ['full_name' => 'Awa Bamba']); // recurrent, pas Force

    $force = $this->families['Force']->id;

    expect(($this->ids)("family_id={$force}&search=awa&sort=created_at"))->toHaveCount(2)
        ->and(($this->ids)("family_id={$force}&search=awa&status=recurrent"))->toBe([$match->id])
        ->and(($this->ids)("family_id={$force}&search=awa&status=recurrent&from=2026-08-02"))->toBe([]);
});

it('trie selon la liste blanche', function (): void {
    $b = AdminFixtures::visitor(['2026-09-01', '2026-09-20'], ['full_name' => 'Bamba Awa']);
    $a = AdminFixtures::visitor(['2026-09-05'], ['full_name' => 'Adjoua Yao']);
    $c = AdminFixtures::visitor(['2026-09-03', '2026-09-10'], ['full_name' => 'Coulibaly Seydou']);
    $none = AdminFixtures::visitor([], ['full_name' => 'Zadi Ange', 'created_at' => AdminFixtures::now()->subYear()]);

    expect(($this->ids)())->toBe([$a->id, $c->id, $b->id, $none->id])
        ->and(($this->ids)('sort=-created_at'))->toBe([$a->id, $c->id, $b->id, $none->id])
        ->and(($this->ids)('sort=created_at'))->toBe([$none->id, $b->id, $c->id, $a->id])
        ->and(($this->ids)('sort=full_name'))->toBe([$a->id, $b->id, $c->id, $none->id])
        ->and(($this->ids)('sort=-last_visit_date'))->toBe([$b->id, $c->id, $a->id, $none->id]);
});

it('charge les agrégats sans N+1 (nombre de requêtes constant)', function (): void {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/admin/visitors?per_page=100')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    AdminFixtures::visitor(['2026-09-01']);
    $before = $count();

    foreach (range(2, 12) as $day) {
        AdminFixtures::visitor([sprintf('2026-08-%02d', $day), sprintf('2026-09-%02d', $day)]);
    }

    expect($count())->toBe($before);
    expect(Visitor::query()->count())->toBe(12);
});
