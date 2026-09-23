<?php

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->settings = app(SettingsService::class);
});

it('fournit des valeurs par défaut sans aucune ligne en base', function (): void {
    expect($this->settings->churchName())->toBe('Église La Maison de la Destinée')
        ->and($this->settings->publicUrl())->toBe(config('app.url'))
        ->and($this->settings->verse())->toBe(['preset' => 0, ...SettingsService::VERSE_PRESETS[0]])
        ->and($this->settings->versePresets())->toBe(SettingsService::VERSE_PRESETS)
        ->and($this->settings->get('inconnu', 'défaut'))->toBe('défaut');
});

it('met en cache les valeurs et invalide le cache à l\'écriture', function (): void {
    $this->settings->set('church_name', 'Première valeur');
    expect($this->settings->churchName())->toBe('Première valeur');

    // Écriture directe en base (hors service) : le cache masque la nouvelle valeur…
    Setting::query()->whereKey('church_name')->update(['value' => json_encode('Valeur directe')]);
    expect($this->settings->churchName())->toBe('Première valeur');

    // … jusqu'à la prochaine écriture via le service.
    $this->settings->set('public_url', 'https://registre.exemple.org');
    expect($this->settings->churchName())->toBe('Valeur directe')
        ->and($this->settings->publicUrl())->toBe('https://registre.exemple.org');
});

it('ne relit pas la base tant que le cache est valide', function (): void {
    $this->settings->all();
    DB::enableQueryLog();

    $this->settings->churchName();
    $this->settings->verse();

    expect(DB::getQueryLog())->toBe([]);
});

it('gère un verset prédéfini ou personnalisé', function (): void {
    $this->settings->setVerse(2);
    expect($this->settings->verse())->toBe(['preset' => 2, 'ref' => 'Psaumes 23:1', 'text' => "L'Éternel est mon berger : je ne manquerai de rien."]);

    $this->settings->setVerse(null, 'Matthieu 11:28', 'Venez à moi, vous tous qui êtes fatigués et chargés.');
    expect($this->settings->verse())->toBe(['preset' => null, 'ref' => 'Matthieu 11:28', 'text' => 'Venez à moi, vous tous qui êtes fatigués et chargés.']);

    // Preset hors bornes : retour au premier verset.
    $this->settings->set('verse', ['preset' => 9]);
    expect($this->settings->verse()['preset'])->toBe(0);
});

it('produit la représentation du contrat', function (): void {
    expect($this->settings->toArray())->toHaveKeys(['church_name', 'public_url', 'verse', 'verse_presets'])
        ->and($this->settings->toArray()['verse'])->toHaveKeys(['preset', 'ref', 'text']);
});
