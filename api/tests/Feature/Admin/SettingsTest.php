<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Admin\Support\AdminFixtures;

beforeEach(function (): void {
    $this->travelTo(AdminFixtures::now());
    $this->seed(SettingsSeeder::class);
    $this->admin = User::factory()->superAdmin()->create();
});

it('renvoie les paramètres au format du contrat, sans enveloppe data', function (): void {
    $this->actingAs(User::factory()->lecteur()->create())
        ->getJson('/api/admin/settings')
        ->assertOk()
        ->assertExactJson([
            'church_name' => 'Église La Maison de la Destinée',
            'public_url' => 'http://localhost',
            'verse' => ['preset' => 0, 'ref' => 'Jean 21:17', 'text' => "Si tu m'aimes, pais mes brebis."],
            'verse_presets' => SettingsService::VERSE_PRESETS,
        ]);
});

it('modifie le nom, l\'URL et choisit un verset prédéfini', function (): void {
    Cache::put('public:config', ['church_name' => 'périmé'], 60);

    $response = $this->actingAs($this->admin)
        ->putJson('/api/admin/settings', [
            'church_name' => '  Église MDD Abidjan ',
            'public_url' => 'https://registre.exemple.org',
            'verse' => ['preset' => 2],
        ])
        ->assertOk()
        ->assertExactJson([
            'church_name' => 'Église MDD Abidjan',
            'public_url' => 'https://registre.exemple.org',
            'verse' => ['preset' => 2, 'ref' => 'Psaumes 23:1', 'text' => "L'Éternel est mon berger : je ne manquerai de rien."],
            'verse_presets' => SettingsService::VERSE_PRESETS,
        ]);

    // Le PUT renvoie exactement le même objet que GET.
    $this->getJson('/api/admin/settings')->assertExactJson($response->json());

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe('settings.updated')
        ->and($log->user_id)->toBe($this->admin->id)
        ->and($log->subject_type)->toBeNull()
        ->and($log->meta)->toBe(['fields' => ['church_name', 'public_url', 'verse']])
        ->and(Cache::has('public:config'))->toBeFalse();
});

it('enregistre un verset personnalisé', function (): void {
    $this->actingAs($this->admin)
        ->putJson('/api/admin/settings', ['verse' => ['preset' => null, 'ref' => 'Jean 14:6', 'text' => 'Je suis le chemin, la vérité, et la vie.']])
        ->assertOk()
        ->assertJsonPath('verse', ['preset' => null, 'ref' => 'Jean 14:6', 'text' => 'Je suis le chemin, la vérité, et la vie.'])
        ->assertJsonPath('church_name', 'Église La Maison de la Destinée');

    expect(AuditLog::query()->sole()->meta)->toBe(['fields' => ['verse']]);
});

it('n\'enregistre que les champs envoyés et ne journalise pas une valeur inchangée', function (): void {
    $this->actingAs($this->admin)
        ->putJson('/api/admin/settings', ['church_name' => 'Église La Maison de la Destinée'])
        ->assertOk();

    $this->putJson('/api/admin/settings', [])->assertOk();

    expect(AuditLog::query()->count())->toBe(0);
});

it('valide les paramètres (422)', function (array $payload, string $field): void {
    $this->actingAs($this->admin)
        ->putJson('/api/admin/settings', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation')
        ->assertJsonValidationErrors([$field]);

    expect(AuditLog::query()->count())->toBe(0);
})->with([
    'nom vide' => [['church_name' => ''], 'church_name'],
    'nom trop long' => [['church_name' => str_repeat('a', 121)], 'church_name'],
    'URL invalide' => [['public_url' => 'registre.exemple.org'], 'public_url'],
    'URL javascript' => [['public_url' => 'javascript:alert(1)'], 'public_url'],
    'URL ftp' => [['public_url' => 'ftp://exemple.org'], 'public_url'],
    'verset vide' => [['verse' => []], 'verse'],
    'preset absent' => [['verse' => ['ref' => 'Jean 1:1', 'text' => 'Au commencement']], 'verse.preset'],
    'preset hors liste' => [['verse' => ['preset' => 3]], 'verse.preset'],
    'preset négatif' => [['verse' => ['preset' => -1]], 'verse.preset'],
    'preset texte' => [['verse' => ['preset' => 'deux']], 'verse.preset'],
    'personnalisé sans référence' => [['verse' => ['preset' => null, 'text' => 'Texte']], 'verse.ref'],
    'personnalisé sans texte' => [['verse' => ['preset' => null, 'ref' => 'Jean 1:1']], 'verse.text'],
    'référence trop longue' => [['verse' => ['preset' => null, 'ref' => str_repeat('a', 61), 'text' => 'Texte']], 'verse.ref'],
    'texte trop long' => [['verse' => ['preset' => null, 'ref' => 'Jean 1:1', 'text' => str_repeat('a', 501)]], 'verse.text'],
    'clé inconnue' => [['verse' => ['preset' => 0, 'couleur' => 'rouge']], 'verse'],
]);

it('exige une URL https en production', function (): void {
    $this->app['env'] = 'production';

    $this->actingAs($this->admin)
        ->putJson('/api/admin/settings', ['public_url' => 'http://registre.exemple.org'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['public_url']);

    $this->putJson('/api/admin/settings', ['public_url' => 'https://registre.exemple.org'])->assertOk();
});
