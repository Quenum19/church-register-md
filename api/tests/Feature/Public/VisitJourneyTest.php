<?php

use App\Enums\PhoneCountry;
use App\Enums\Source;
use App\Enums\VisitorStatus;
use App\Models\Member;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\Journey\VisitTokenService;
use App\Services\VisitorStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\Feature\Public\Support\PublicJourney;

uses(PublicJourney::class);

beforeEach(function (): void {
    $this->seedFamilies();
    $this->travelToAbidjan('2026-09-22');
});

/*
|--------------------------------------------------------------------------
| Parcours complet
|--------------------------------------------------------------------------
*/

it('enregistre le parcours 1 → 2 → 3 sur trois jours, avec les statuts successifs', function (): void {
    // Visite 1 : dimanche 20 septembre (famille de septembre : Force).
    $this->travelToAbidjan('2026-09-20', '09:30');

    $this->postVisit($this->tokenFor(), $this->visit1Answers([
        'whatsapp_same_as_phone' => true,
        'wants_whatsapp_group' => true,
    ]))
        ->assertCreated()
        ->assertExactJson(['visit_number' => 1, 'family' => ['id' => $this->familyId('Force'), 'name' => 'Force'], 'completed' => false]);

    $visitor = Visitor::query()->where('phone', $this->e164)->firstOrFail();

    expect($visitor->full_name)->toBe('Aya Kouassi')
        ->and($visitor->commune)->toBe('Cocody')
        ->and($visitor->quartier)->toBe('Angré')
        ->and($visitor->source)->toBe(Source::BoucheAOreille)
        ->and($visitor->whatsapp)->toBe($this->e164)
        ->and($visitor->wants_whatsapp_group)->toBeTrue()
        ->and($visitor->consent_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and($visitor->status)->toBe(VisitorStatus::Prospect);

    $first = Visit::query()->where('visitor_id', $visitor->id)->sole();
    expect($first->visit_number)->toBe(1)
        ->and($first->visit_date->toDateString())->toBe('2026-09-20')
        ->and($first->family_id)->toBe($this->familyId('Force'))
        ->and($first->getRawOriginal('answers'))->toBe('{}');

    // Visite 2 : dimanche 27 septembre.
    $this->travelToAbidjan('2026-09-27', '10:15');

    $this->identify()->assertJsonPath('step', 2);
    $this->postVisit($this->tokenFor(), $this->visit2Answers([
        'return_reasons' => ['enseignement', 'autres'],
        'return_reasons_other' => 'La prière du jeudi',
    ]), null)
        ->assertCreated()
        ->assertExactJson(['visit_number' => 2, 'family' => ['id' => $this->familyId('Force'), 'name' => 'Force'], 'completed' => false]);

    expect($visitor->refresh()->status)->toBe(VisitorStatus::Recurrent);

    // Visite 3 : dimanche 4 octobre (famille d'octobre : Honneur).
    $this->travelToAbidjan('2026-10-04', '11:00');

    $this->identify()->assertJsonPath('step', 3);
    $this->postVisit($this->tokenFor(), $this->visit3Answers(), null)
        ->assertCreated()
        ->assertExactJson(['visit_number' => 3, 'family' => ['id' => $this->familyId('Honneur'), 'name' => 'Honneur'], 'completed' => true]);

    expect($visitor->refresh()->status)->toBe(VisitorStatus::MembrePotentiel);

    $visits = Visit::query()->where('visitor_id', $visitor->id)->orderBy('visit_number')->get();

    expect($visits->pluck('visit_date')->map->toDateString()->all())->toBe(['2026-09-20', '2026-09-27', '2026-10-04'])
        ->and($visits[1]->answers)->toBe(['return_reasons' => ['enseignement', 'autres'], 'return_reasons_other' => 'La prière du jeudi'])
        ->and($visits[2]->answers)->toBe(['visit_reason' => 'devenir_membre', 'visit_reason_other' => null]);

    // Parcours terminé : le jour même « done_today », ensuite « complete ».
    $this->identify()->assertJsonPath('step', 'done_today');
    $this->travelToAbidjan('2026-10-11');
    $this->identify()->assertExactJson(['step' => 'complete', 'session_token' => null, 'expires_in' => null]);
});

it('renvoie « done_today » après la 1re visite, le jour même', function (): void {
    $this->registerFirstVisit();

    $this->identify()->assertExactJson(['step' => 'done_today', 'session_token' => null, 'expires_in' => null]);
});

/*
|--------------------------------------------------------------------------
| Jeton de parcours
|--------------------------------------------------------------------------
*/

it('refuse un jeton falsifié ou illisible (401 token_invalid)', function (Closure $forge): void {
    $token = $forge($this->tokenFor());

    $this->postVisit($token, $this->visit1Answers())
        ->assertUnauthorized()
        ->assertJsonPath('code', 'token_invalid');

    expect(Visitor::query()->count())->toBe(0);
})->with([
    'caractère modifié' => [fn (string $token): string => substr($token, 0, 40).(($token[40] === 'A') ? 'B' : 'A').substr($token, 41)],
    'chaîne quelconque' => [fn (): string => 'pas-un-jeton'],
    'chiffré avec une autre clé' => [fn (): string => (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encryptString(json_encode([
        'phone_e164' => '+2250700000000', 'country' => 'CI', 'step' => 1, 'jti' => str_repeat('a', 32), 'exp' => time() + 900,
    ]))],
    'contenu inattendu' => [fn (): string => Crypt::encryptString(json_encode(['phone_e164' => '+2250700000000', 'step' => 1]))],
    'étape hors limites' => [fn (): string => Crypt::encryptString(json_encode([
        'phone_e164' => '+2250700000000', 'country' => 'CI', 'step' => 4, 'jti' => str_repeat('a', 32), 'exp' => time() + 900,
    ]))],
    'sérialisation PHP' => [fn (): string => Crypt::encrypt(['phone_e164' => '+2250700000000'])],
]);

it('refuse un jeton expiré (410 token_expired)', function (): void {
    $token = $this->tokenFor();

    $this->travel(15)->minutes();

    $this->postVisit($token, $this->visit1Answers())
        ->assertStatus(410)
        ->assertJsonPath('code', 'token_expired');

    expect(Visitor::query()->count())->toBe(0);
});

it('accepte un jeton jusqu\'à la fin de ses 15 minutes', function (): void {
    $token = $this->tokenFor();

    $this->travel(14 * 60 + 59)->seconds();

    $this->postVisit($token, $this->visit1Answers())->assertCreated();
});

it('refuse un jeton déjà utilisé (409 token_used)', function (): void {
    $token = $this->tokenFor();

    $this->postVisit($token, $this->visit1Answers())->assertCreated();

    // Nouvelle clé d'idempotence (pas un rejeu) avec le même jeton.
    $this->postVisit($token, $this->visit1Answers(['full_name' => 'Autre Nom']))
        ->assertStatus(409)
        ->assertJsonPath('code', 'token_used');

    $this->travelToAbidjan('2026-09-29');
    $this->postVisit($token, $this->visit2Answers(), null)->assertStatus(410);

    expect(Visitor::query()->sole()->full_name)->toBe('Aya Kouassi')
        ->and(Visit::query()->count())->toBe(1);
});

it('garde le jeton consommé en mémoire au moins jusqu\'à son expiration', function (): void {
    $token = $this->tokenFor();
    $this->postVisit($token, $this->visit1Answers())->assertCreated();

    $decoded = app(VisitTokenService::class)->decode($token);

    $this->travel(14)->minutes();
    expect(app(VisitTokenService::class)->isConsumed($decoded))->toBeTrue();
});

it('ne consomme pas le jeton quand les réponses sont invalides', function (): void {
    $token = $this->tokenFor();

    $this->postVisit($token, $this->visit1Answers(['full_name' => '']))->assertUnprocessable();
    $this->postVisit($token, $this->visit1Answers())->assertCreated();
});

/*
|--------------------------------------------------------------------------
| Étape attendue (step_mismatch) et une visite par jour (already_today)
|--------------------------------------------------------------------------
*/

it('refuse un second jeton d\'étape 1 une fois le visiteur créé (409 step_mismatch)', function (): void {
    $first = $this->tokenFor();
    $second = $this->tokenFor();

    $this->postVisit($first, $this->visit1Answers())->assertCreated();

    $this->postVisit($second, $this->visit1Answers(['full_name' => 'Nom Usurpé']))
        ->assertStatus(409)
        ->assertJsonPath('code', 'step_mismatch');

    // Le nom n'est jamais modifiable depuis le public.
    expect(Visitor::query()->sole()->full_name)->toBe('Aya Kouassi');
});

it('refuse une étape qui ne suit pas le nombre de visites (409 step_mismatch)', function (): void {
    $visitor = Visitor::factory()->prospect(CarbonImmutable::parse('2026-09-06'))->create(['phone' => $this->e164]);
    $token = $this->tokenFor();

    // La 2e visite est enregistrée ailleurs entre l'identification et l'envoi.
    Visit::factory()->second()->for($visitor)->onDate('2026-09-13')->create();
    app(VisitorStatusService::class)->refresh($visitor);

    $this->postVisit($token, $this->visit2Answers(), null)
        ->assertStatus(409)
        ->assertJsonPath('code', 'step_mismatch');

    expect(Visit::query()->count())->toBe(2);
});

it('refuse un jeton d\'étape 2 ou 3 pour un numéro inconnu', function (int $step): void {
    $answers = $step === 2 ? $this->visit2Answers() : $this->visit3Answers();

    $this->postVisit($this->issueToken($this->e164, $step), $answers, null)
        ->assertStatus(409)
        ->assertJsonPath('code', 'step_mismatch');

    expect(Visitor::query()->count())->toBe(0);
})->with([2, 3]);

it('refuse toute visite à un membre converti', function (): void {
    $visitor = Visitor::factory()->withVisits(2, CarbonImmutable::parse('2026-08-02'))->create(['phone' => $this->e164]);
    Member::query()->create(['visitor_id' => $visitor->id, 'converted_by' => null, 'converted_at' => now()]);
    app(VisitorStatusService::class)->refresh($visitor);

    $this->postVisit($this->issueToken($this->e164, 3), $this->visit3Answers(), null)
        ->assertStatus(409)
        ->assertJsonPath('code', 'step_mismatch');
});

it('refuse une deuxième visite le même jour (409 already_today)', function (): void {
    $this->registerFirstVisit();

    // /identify répond « done_today » ; un jeton d'étape 2 obtenu autrement est refusé par l'index unique.
    $this->postVisit($this->issueToken($this->e164, 2), $this->visit2Answers(), null)
        ->assertStatus(409)
        ->assertJsonPath('code', 'already_today');

    $visitor = Visitor::query()->sole();

    expect(Visit::query()->count())->toBe(1)
        ->and($visitor->status)->toBe(VisitorStatus::Prospect);
});

/*
|--------------------------------------------------------------------------
| Idempotence
|--------------------------------------------------------------------------
*/

it('rejoue une visite déjà enregistrée (200, même corps, sans doublon)', function (): void {
    $token = $this->tokenFor();
    $key = (string) Str::uuid();

    $created = $this->postVisit($token, $this->visit1Answers(), true, $key)->assertCreated()->json();

    $this->postVisit($token, $this->visit1Answers(), true, $key)
        ->assertOk()
        ->assertExactJson($created);

    expect(Visit::query()->count())->toBe(1)->and(Visitor::query()->count())->toBe(1);
});

it('rejoue même après l\'expiration du jeton, sans revalider les réponses', function (): void {
    $token = $this->tokenFor();
    $key = (string) Str::uuid();

    $created = $this->postVisit($token, $this->visit1Answers(), true, $key)->assertCreated()->json();

    $this->travel(20)->minutes();

    $this->postVisit($token, [], null, $key)->assertOk()->assertExactJson($created);

    // Le lendemain aussi (renvoi tardif après une coupure réseau).
    $this->travelToAbidjan('2026-09-23', '08:00');
    $this->postVisit($token, [], null, $key)->assertOk()->assertExactJson($created);
});

it('rejoue avec une clé en majuscules (même UUID)', function (): void {
    $token = $this->tokenFor();
    $key = (string) Str::uuid();

    $this->postVisit($token, $this->visit1Answers(), true, $key)->assertCreated();
    $this->postVisit($token, $this->visit1Answers(), true, Str::upper($key))->assertOk();

    expect(Visit::query()->sole()->idempotency_key)->toBe($key);
});

it('refuse une clé déjà utilisée pour un autre numéro (409 idempotency_conflict)', function (): void {
    $key = (string) Str::uuid();

    $this->postVisit($this->tokenFor(), $this->visit1Answers(), true, $key)->assertCreated();

    $other = $this->tokenFor('05 00 00 00 00');

    $this->postVisit($other, $this->visit1Answers(['full_name' => 'Kofi Yao']), true, $key)
        ->assertStatus(409)
        ->assertExactJson([
            'message' => 'Cet envoi a déjà été utilisé pour un autre enregistrement. Merci de réessayer.',
            'code' => 'idempotency_conflict',
        ]);

    // Jeton illisible : impossible de prouver qu'il s'agit du même numéro.
    $this->postVisit('pas-un-jeton', [], null, $key)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');

    // Avec une nouvelle clé, l'autre numéro est enregistré normalement.
    $this->postVisit($other, $this->visit1Answers(['full_name' => 'Kofi Yao']))->assertCreated();

    expect(Visitor::query()->count())->toBe(2);
});

it('rejoue pour le même numéro saisi dans un autre pays (même E.164)', function (): void {
    $key = (string) Str::uuid();

    $created = $this->postVisit($this->tokenFor(), $this->visit1Answers(), true, $key)->assertCreated()->json();

    $this->postVisit($this->issueToken($this->e164, 1, PhoneCountry::OTHER), [], null, $key)
        ->assertOk()
        ->assertExactJson($created);
});
