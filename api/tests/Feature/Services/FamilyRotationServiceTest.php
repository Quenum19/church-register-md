<?php

use App\Models\Family;
use App\Models\FamilyRotation;
use App\Services\FamilyRotationService;
use Carbon\CarbonImmutable;
use Database\Seeders\FamilySeeder;

beforeEach(function (): void {
    $this->seed(FamilySeeder::class);
    $this->rotations = app(FamilyRotationService::class);
});

it('respecte l\'ancre historique et l\'ordre de rotation', function (string $date, string $expected): void {
    expect($this->rotations->familyFor(CarbonImmutable::parse($date))?->name)->toBe($expected);
})->with([
    'ancre novembre 2025' => ['2025-11-15', 'Puissance'],
    'décembre 2025' => ['2025-12-01', 'Richesse'],
    'janvier 2026' => ['2026-01-31', 'Sagesse'],
    'février 2026' => ['2026-02-01', 'Force'],
    'mai 2026' => ['2026-05-10', 'Louange'],
    'juin 2026 (nouveau cycle)' => ['2026-06-10', 'Puissance'],
    'septembre 2026' => ['2026-09-22', 'Force'],
    'décembre 2036 (dernier mois seedé)' => ['2036-12-31', 'Puissance'],
]);

it('interprète la date dans le fuseau Africa/Abidjan', function (): void {
    // 1er février 00:30 à Paris = 31 janvier 23:30 à Abidjan : mois de janvier (Sagesse).
    $date = CarbonImmutable::parse('2026-02-01 00:30', 'Europe/Paris');

    expect($this->rotations->familyFor($date)?->name)->toBe('Sagesse');
});

it('renvoie null pour un mois sans rotation', function (): void {
    expect($this->rotations->familyFor(CarbonImmutable::parse('2025-10-15')))->toBeNull()
        ->and($this->rotations->familyForMonth(2040, 1))->toBeNull();
});

it('donne la famille du mois courant et du mois suivant', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-22 10:00', 'Africa/Abidjan'));

    expect($this->rotations->current()?->name)->toBe('Force')
        ->and($this->rotations->next()?->name)->toBe('Honneur');

    $this->travelTo(CarbonImmutable::parse('2026-01-31 12:00', 'Africa/Abidjan'));

    expect($this->rotations->next()?->name)->toBe('Force');
});

it('complète les mois manquants en poursuivant l\'ordre sans modifier l\'existant', function (): void {
    $this->travelTo(CarbonImmutable::parse('2035-06-15', 'Africa/Abidjan'));

    // Une rotation modifiée manuellement reste intacte ; la suite repart de cette famille.
    $gloire = Family::query()->where('name', 'Gloire')->firstOrFail();
    FamilyRotation::query()->where(['year' => 2036, 'month' => 12])->update(['family_id' => $gloire->id]);

    $created = $this->rotations->ensureMonthsAhead(24);

    // Mois courant (2035-06) + 24 mois = jusqu'à 2037-06 ; 2025-11 → 2036-12 existe déjà.
    expect($created)->toBe(6)
        ->and($this->rotations->familyForMonth(2036, 12)?->name)->toBe('Gloire')
        ->and($this->rotations->familyForMonth(2037, 1)?->name)->toBe('Louange')
        ->and($this->rotations->familyForMonth(2037, 2)?->name)->toBe('Puissance')
        ->and($this->rotations->familyForMonth(2037, 6)?->name)->toBe('Honneur')
        ->and($this->rotations->familyForMonth(2037, 7))->toBeNull()
        ->and($this->rotations->ensureMonthsAhead(24))->toBe(0);
});

it('comble un trou au milieu de la rotation', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01', 'Africa/Abidjan'));
    FamilyRotation::query()->where(['year' => 2026, 'month' => 10])->delete();

    expect($this->rotations->ensureMonthsAhead(3))->toBe(1)
        ->and($this->rotations->familyForMonth(2026, 10)?->name)->toBe('Honneur');
});

it('part de l\'ancre quand aucune rotation n\'existe', function (): void {
    FamilyRotation::query()->delete();
    $this->travelTo(CarbonImmutable::parse('2026-09-10', 'Africa/Abidjan'));

    expect($this->rotations->ensureMonthsAhead(2))->toBe(3)
        ->and($this->rotations->familyForMonth(2026, 9)?->name)->toBe('Force')
        ->and($this->rotations->familyForMonth(2026, 11)?->name)->toBe('Gloire');
});

it('ignore les familles inactives pour les nouveaux mois', function (): void {
    $this->travelTo(CarbonImmutable::parse('2036-12-10', 'Africa/Abidjan'));
    Family::query()->where('name', 'Richesse')->update(['active' => false]);

    $this->rotations->ensureMonthsAhead(2);

    // 2036-12 = Puissance ; Richesse est sautée.
    expect($this->rotations->familyForMonth(2037, 1)?->name)->toBe('Sagesse')
        ->and($this->rotations->familyForMonth(2037, 2)?->name)->toBe('Force');
});

it('expose la commande rotations:extend', function (): void {
    $this->travelTo(CarbonImmutable::parse('2035-06-15', 'Africa/Abidjan'));

    $this->artisan('rotations:extend')
        ->expectsOutputToContain('6 rotation(s) créée(s)')
        ->assertSuccessful();

    $this->artisan('rotations:extend', ['--months' => 'abc'])->assertFailed();
});
