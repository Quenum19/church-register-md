<?php

use App\Models\Family;
use App\Models\FamilyRotation;
use App\Models\Setting;
use App\Services\Journey\PublicConfigService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Public\Support\PublicJourney;

uses(PublicJourney::class);

beforeEach(function (): void {
    $this->seedFamilies();
    $this->travelToAbidjan('2026-09-22');
});

it('renvoie exactement la configuration du contrat', function (): void {
    app(SettingsService::class)->set('public_url', 'https://registre.exemple.org');

    $response = $this->getJson('/api/public/config')->assertOk();

    $this->assertStateless($response);

    $families = collect(['Puissance', 'Richesse', 'Sagesse', 'Force', 'Honneur', 'Gloire', 'Louange'])
        ->map(fn (string $name): array => ['id' => $this->familyId($name), 'name' => $name])
        ->all();

    $response->assertExactJson([
        'church_name' => 'Église La Maison de la Destinée',
        'public_url' => 'https://registre.exemple.org',
        'verse' => ['ref' => 'Jean 21:17', 'text' => "Si tu m'aimes, pais mes brebis."],
        'current_family' => ['id' => $this->familyId('Force'), 'name' => 'Force'],
        'families' => $families,
    ]);
});

it('renvoie le verset personnalisé sans ses métadonnées', function (): void {
    app(SettingsService::class)->setVerse(null, 'Psaumes 121:1', 'Je lève mes yeux vers les montagnes.');

    $this->getJson('/api/public/config')
        ->assertOk()
        ->assertExactJsonStructure([
            'church_name', 'public_url', 'verse' => ['ref', 'text'],
            'current_family' => ['id', 'name'], 'families' => ['*' => ['id', 'name']],
        ])
        ->assertJsonPath('verse', ['ref' => 'Psaumes 121:1', 'text' => 'Je lève mes yeux vers les montagnes.']);
});

it('renvoie current_family null quand aucune rotation n\'est définie pour le mois', function (): void {
    FamilyRotation::query()->where(['year' => 2026, 'month' => 9])->delete();

    $this->getJson('/api/public/config')->assertOk()->assertJsonPath('current_family', null);
});

it('ne liste que les familles actives, dans l\'ordre de rotation', function (): void {
    Family::query()->where('name', 'Gloire')->update(['active' => false]);

    $names = collect($this->getJson('/api/public/config')->assertOk()->json('families'))->pluck('name')->all();

    expect($names)->toBe(['Puissance', 'Richesse', 'Sagesse', 'Force', 'Honneur', 'Louange']);
});

it('met la configuration en cache 60 secondes sous la clé public:config', function (): void {
    $this->getJson('/api/public/config')->assertOk();

    expect(PublicConfigService::CACHE_KEY)->toBe('public:config')
        ->and(Cache::has('public:config'))->toBeTrue();

    // Modification en base sans passer par l'API admin : la valeur en cache est servie.
    Setting::query()->updateOrCreate(['key' => 'church_name'], ['value' => 'Nouveau nom']);
    app(SettingsService::class)->flush();

    $this->getJson('/api/public/config')->assertJsonPath('church_name', 'Église La Maison de la Destinée');

    // Au-delà de 60 secondes, la configuration est recalculée.
    $this->travel(61)->seconds();
    $this->getJson('/api/public/config')->assertJsonPath('church_name', 'Nouveau nom');
});

it('est recalculée dès que la clé public:config est vidée (API admin)', function (): void {
    $this->getJson('/api/public/config')->assertJsonPath('current_family.name', 'Force');

    FamilyRotation::query()->where(['year' => 2026, 'month' => 9])->update(['family_id' => $this->familyId('Gloire')]);
    $this->getJson('/api/public/config')->assertJsonPath('current_family.name', 'Force');

    Cache::forget('public:config');

    $this->getJson('/api/public/config')->assertJsonPath('current_family', ['id' => $this->familyId('Gloire'), 'name' => 'Gloire']);
});
