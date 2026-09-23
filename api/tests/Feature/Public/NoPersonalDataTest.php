<?php

use App\Models\Visitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Public\Support\PublicJourney;

uses(PublicJourney::class);

beforeEach(function (): void {
    $this->seedFamilies();
    $this->travelToAbidjan('2026-09-20');
});

it('ne renvoie aucune donnée personnelle, quelle que soit la réponse publique', function (): void {
    // Données personnelles du visiteur et d'un tiers (numéros sous toutes leurs formes).
    $personal = [
        'Aya Kouassi', 'Cocody', 'Angré', 'Jean Kouadio', 'Un concert',
        '0700000000', '2250700000000', '07 00 00 00 00',
        '0500000000', '2250500000000',
        '0102030405', '2250102030405',
    ];

    $check = fn ($response) => $this->assertNoPersonalData($response, $personal);

    // Configuration.
    $check($this->getJson('/api/public/config')->assertOk());

    // Identification (étape 1) puis visite 1 complète.
    $token = $check($this->identify()->assertOk())->json('session_token');
    $key = (string) Str::uuid();

    $check($this->postVisit($token, $this->visit1Answers([
        'source' => 'invite_membre',
        'invited_by' => 'Jean Kouadio',
        'inviter_family_id' => $this->familyId('Force'),
        'whatsapp' => ['country' => 'CI', 'number' => '0500000000'],
        'wants_whatsapp_group' => true,
    ]), true, $key)->assertCreated());

    // Rejeu, jeton réutilisé, clé d'un autre numéro, jeton invalide, validation.
    $check($this->postVisit($token, [], null, $key)->assertOk());
    $check($this->postVisit($token, $this->visit1Answers())->assertStatus(409));
    $check($this->postVisit($this->tokenFor('01 02 03 04 05'), $this->visit1Answers(), true, $key)->assertStatus(409));
    $check($this->postVisit('jeton-invalide', $this->visit1Answers())->assertUnauthorized());
    $check($this->postVisit($this->tokenFor('01 02 03 04 05'), $this->visit1Answers([
        'full_name' => str_repeat('Aya Kouassi ', 20),
        'source' => 'autre',
        'source_other' => str_repeat('Un concert ', 30),
        'whatsapp' => ['country' => 'CI', 'number' => '0700000000 0500000000'],
    ]), false)->assertUnprocessable());

    // Même jour : « done_today » ; identification invalide (422).
    $check($this->identify()->assertOk()->assertJsonPath('step', 'done_today'));
    $check($this->identify('07 00 00 00 00 00')->assertUnprocessable());

    // Visite 2 le dimanche suivant, puis jeton expiré et visite du jour déjà faite.
    $this->travelToAbidjan('2026-09-27');
    $token = $check($this->identify()->assertOk()->assertJsonPath('step', 2))->json('session_token');
    $check($this->postVisit($token, $this->visit2Answers(['return_reasons' => ['autres'], 'return_reasons_other' => 'Un concert']), null)->assertCreated());

    $expired = $this->tokenFor('01 02 03 04 05');
    $this->travel(16)->minutes();
    $check($this->postVisit($expired, $this->visit1Answers())->assertStatus(410));
    $check($this->postVisit($this->issueToken('+2250700000000', 3), $this->visit3Answers(), null)->assertStatus(409));

    // Trop de tentatives (429).
    foreach (range(1, 5) as $ignored) {
        $this->identify('05 00 00 00 00');
    }
    $check($this->identify('05 00 00 00 00')->assertTooManyRequests());

    expect(Visitor::query()->where('phone', '+2250700000000')->sole()->whatsapp)->toBe('+2250500000000');
});

it('renvoie la même forme de réponse d\'identification pour un numéro connu ou inconnu', function (): void {
    Visitor::factory()->prospect(CarbonImmutable::parse('2026-09-13'))->create([
        'phone' => '+2250700000000',
        'full_name' => 'Aya Kouassi',
    ]);

    $known = $this->identify()->assertOk()->json();
    $unknown = $this->identify('05 00 00 00 00')->assertOk()->json();

    expect(array_keys($known))->toBe(array_keys($unknown))
        ->and(gettype($known['session_token']))->toBe(gettype($unknown['session_token']))
        ->and($known['expires_in'])->toBe($unknown['expires_in']);
});
