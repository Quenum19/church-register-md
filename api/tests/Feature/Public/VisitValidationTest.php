<?php

use App\Enums\Source;
use App\Models\Family;
use App\Models\Visit;
use App\Models\Visitor;
use Illuminate\Support\Str;
use Tests\Feature\Public\Support\PublicJourney;

uses(PublicJourney::class);

beforeEach(function (): void {
    $this->seedFamilies();
    $this->travelToAbidjan('2026-09-22');
});

/*
|--------------------------------------------------------------------------
| Forme de la requête (avant le rejeu et le jeton)
|--------------------------------------------------------------------------
*/

it('valide la forme de la requête avant tout le reste', function (array $payload, string $field): void {
    $response = $this->postJson('/api/public/visits', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation')
        ->assertJsonValidationErrors([$field]);

    $this->assertStateless($response);
})->with([
    'jeton absent' => [['idempotency_key' => '9b2f1c2e-6a4d-4c8b-9f3e-1a2b3c4d5e6f', 'answers' => []], 'session_token'],
    'jeton non textuel' => [['session_token' => ['x'], 'idempotency_key' => '9b2f1c2e-6a4d-4c8b-9f3e-1a2b3c4d5e6f', 'answers' => []], 'session_token'],
    'jeton démesuré' => [['session_token' => str_repeat('a', 2049), 'idempotency_key' => '9b2f1c2e-6a4d-4c8b-9f3e-1a2b3c4d5e6f', 'answers' => []], 'session_token'],
    'clé absente' => [['session_token' => 'x', 'answers' => []], 'idempotency_key'],
    'clé non UUID' => [['session_token' => 'x', 'idempotency_key' => 'cle-123', 'answers' => []], 'idempotency_key'],
    'réponses absentes' => [['session_token' => 'x', 'idempotency_key' => '9b2f1c2e-6a4d-4c8b-9f3e-1a2b3c4d5e6f'], 'answers'],
    'réponses non objet' => [['session_token' => 'x', 'idempotency_key' => '9b2f1c2e-6a4d-4c8b-9f3e-1a2b3c4d5e6f', 'answers' => 'oui'], 'answers'],
    'consentement non booléen' => [['session_token' => 'x', 'idempotency_key' => '9b2f1c2e-6a4d-4c8b-9f3e-1a2b3c4d5e6f', 'answers' => [], 'consent' => 'oui'], 'consent'],
]);

/*
|--------------------------------------------------------------------------
| Étape 1
|--------------------------------------------------------------------------
*/

it('exige le consentement à l\'étape 1', function (?bool $consent): void {
    $this->postVisit($this->tokenFor(), $this->visit1Answers(), $consent)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['consent' => 'Votre accord est nécessaire pour enregistrer votre visite.']);

    expect(Visitor::query()->count())->toBe(0);
})->with(['refusé' => [false], 'absent' => [null]]);

it('applique les règles de l\'étape 1', function (array $overrides, string $field, string $message): void {
    $response = $this->postVisit($this->tokenFor(), $this->visit1Answers($overrides))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation')
        ->assertJsonValidationErrors([$field => $message]);

    expect(array_keys($response->json('errors')))->toBe([$field])
        ->and(Visitor::query()->count())->toBe(0);
})->with([
    'nom absent' => [['full_name' => null], 'answers.full_name', 'Indiquez votre nom et vos prénoms.'],
    'nom vide après rognage' => [['full_name' => "   \t "], 'answers.full_name', 'Indiquez votre nom et vos prénoms.'],
    'nom trop long' => [['full_name' => str_repeat('é', 101)], 'answers.full_name', 'Le nom ne doit pas dépasser 100 caractères.'],
    'nom non textuel' => [['full_name' => 42], 'answers.full_name', 'Le nom est invalide.'],
    'commune absente' => [['commune' => ''], 'answers.commune', 'Indiquez votre commune.'],
    'commune trop longue' => [['commune' => str_repeat('a', 81)], 'answers.commune', 'La commune ne doit pas dépasser 80 caractères.'],
    'quartier absent' => [['quartier' => null], 'answers.quartier', 'Indiquez votre quartier.'],
    'quartier trop long' => [['quartier' => str_repeat('a', 81)], 'answers.quartier', 'Le quartier ne doit pas dépasser 80 caractères.'],
    'source absente' => [['source' => null], 'answers.source', "Indiquez comment vous avez connu l'Église."],
    'source inconnue' => [['source' => 'television'], 'answers.source', "Indiquez comment vous avez connu l'Église."],
    'autre sans précision' => [['source' => 'autre'], 'answers.source_other', "Précisez comment vous avez connu l'Église."],
    'précision trop longue' => [['source' => 'autre', 'source_other' => str_repeat('a', 201)], 'answers.source_other', 'La précision ne doit pas dépasser 200 caractères.'],
    'invité sans invitant' => [['source' => 'invite_membre'], 'answers.invited_by', 'Indiquez le nom de la personne qui vous a invité(e).'],
    'invitant trop long' => [['source' => 'invite_membre', 'invited_by' => str_repeat('a', 101)], 'answers.invited_by', 'Le nom de la personne qui vous a invité(e) ne doit pas dépasser 100 caractères.'],
    'famille inexistante' => [['source' => 'invite_membre', 'invited_by' => 'Jean Kouadio', 'inviter_family_id' => 999999], 'answers.inviter_family_id', 'La famille sélectionnée est invalide.'],
    'famille non numérique' => [['source' => 'invite_membre', 'invited_by' => 'Jean Kouadio', 'inviter_family_id' => 'Force'], 'answers.inviter_family_id', 'La famille sélectionnée est invalide.'],
    'famille sans invitation' => [['source' => 'passage', 'inviter_family_id' => 1], 'answers.inviter_family_id', "La famille de l'invitant ne s'indique que pour une invitation par un membre."],
    'WhatsApp invalide' => [['whatsapp' => ['country' => 'CI', 'number' => '0700']], 'answers.whatsapp.number', 'Le numéro WhatsApp est invalide.'],
    'WhatsApp trop long' => [['whatsapp' => ['country' => 'CI', 'number' => str_repeat('0', 33)]], 'answers.whatsapp.number', 'Le numéro WhatsApp est invalide.'],
    'WhatsApp pays inconnu' => [['whatsapp' => ['country' => 'ZZ', 'number' => '0500000000']], 'answers.whatsapp.country', 'Le pays du numéro WhatsApp est invalide.'],
    'WhatsApp sans pays' => [['whatsapp' => ['number' => '0500000000']], 'answers.whatsapp.country', 'Choisissez le pays du numéro WhatsApp.'],
    'WhatsApp non objet' => [['whatsapp' => '0500000000'], 'answers.whatsapp', 'Le numéro WhatsApp est invalide.'],
    'groupe sans WhatsApp' => [['wants_whatsapp_group' => true], 'answers.whatsapp.number', 'Pour rejoindre le groupe WhatsApp, indiquez votre numéro WhatsApp ou cochez « même numéro ».'],
    'groupe avec WhatsApp vide' => [['wants_whatsapp_group' => true, 'whatsapp' => ['country' => 'CI', 'number' => '  ']], 'answers.whatsapp.number', 'Pour rejoindre le groupe WhatsApp, indiquez votre numéro WhatsApp ou cochez « même numéro ».'],
    'groupe non booléen' => [['wants_whatsapp_group' => 'oui'], 'answers.wants_whatsapp_group', 'Choix invalide.'],
    'même numéro non booléen' => [['whatsapp_same_as_phone' => 'oui'], 'answers.whatsapp_same_as_phone', 'Choix invalide.'],
]);

it('refuse une famille d\'invitant désactivée', function (): void {
    $family = Family::query()->where('name', 'Gloire')->firstOrFail();
    $family->update(['active' => false]);

    $this->postVisit($this->tokenFor(), $this->visit1Answers([
        'source' => 'invite_membre', 'invited_by' => 'Jean Kouadio', 'inviter_family_id' => $family->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors(['answers.inviter_family_id']);
});

it('renvoie toutes les erreurs de l\'étape 1 d\'un coup', function (): void {
    $this->postVisit($this->tokenFor(), [], false)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['consent', 'answers.full_name', 'answers.commune', 'answers.quartier', 'answers.source']);
});

it('rogne les chaînes et stocke le profil de l\'étape 1', function (): void {
    $this->postVisit($this->tokenFor(), $this->visit1Answers([
        'full_name' => "  Aya   Kouassi \u{00A0}",
        'commune' => "\tCocody ",
        'quartier' => ' Angré',
        'source' => 'invite_membre',
        'invited_by' => '  Jean Kouadio  ',
        'inviter_family_id' => $this->familyId('Sagesse'),
        'source_other' => 'ignoré car la source n\'est pas « autre »',
    ]))->assertCreated();

    $visitor = Visitor::query()->sole();

    expect($visitor->full_name)->toBe('Aya   Kouassi')
        ->and($visitor->commune)->toBe('Cocody')
        ->and($visitor->quartier)->toBe('Angré')
        ->and($visitor->source)->toBe(Source::InviteMembre)
        ->and($visitor->invited_by)->toBe('Jean Kouadio')
        ->and($visitor->inviter_family_id)->toBe($this->familyId('Sagesse'))
        ->and($visitor->source_other)->toBeNull()
        ->and($visitor->consent_at)->not->toBeNull();
});

it('ne garde que les champs liés à la source choisie', function (): void {
    $this->postVisit($this->tokenFor(), $this->visit1Answers([
        'source' => 'autre',
        'source_other' => ' Un concert ',
        'invited_by' => 'ignoré',
    ]))->assertCreated();

    $visitor = Visitor::query()->sole();

    expect($visitor->source)->toBe(Source::Autre)
        ->and($visitor->source_other)->toBe('Un concert')
        ->and($visitor->invited_by)->toBeNull()
        ->and($visitor->inviter_family_id)->toBeNull();
});

it('accepte « invité par un membre » sans famille d\'invitant', function (): void {
    $this->postVisit($this->tokenFor(), $this->visit1Answers([
        'source' => 'invite_membre', 'invited_by' => 'Jean Kouadio', 'inviter_family_id' => null,
    ]))->assertCreated();

    expect(Visitor::query()->sole()->inviter_family_id)->toBeNull();
});

it('stocke le WhatsApp en E.164, ou NULL s\'il est vide', function (mixed $whatsapp, ?string $expected): void {
    $this->postVisit($this->tokenFor(), $this->visit1Answers(['whatsapp' => $whatsapp]))->assertCreated();

    expect(Visitor::query()->sole()->getRawOriginal('whatsapp'))->toBe($expected);
})->with([
    'absent (null)' => [null, null],
    'numéro vide' => [['country' => 'CI', 'number' => ''], null],
    'numéro blanc' => [['country' => 'CI', 'number' => '   '], null],
    'objet vide' => [[], null],
    'CI avec espaces' => [['country' => 'CI', 'number' => '05 00 00 00 00'], '+2250500000000'],
    'France' => [['country' => 'FR', 'number' => '0612345678'], '+33612345678'],
    'autre pays' => [['country' => 'OTHER', 'number' => '+447911123456'], '+447911123456'],
]);

it('reprend le numéro identifié quand « même numéro » est coché', function (mixed $whatsapp): void {
    $this->postVisit($this->tokenFor(), $this->visit1Answers([
        'whatsapp_same_as_phone' => true,
        'wants_whatsapp_group' => true,
        'whatsapp' => $whatsapp,
    ]))->assertCreated();

    $visitor = Visitor::query()->sole();

    expect($visitor->whatsapp)->toBe($this->e164)
        ->and($visitor->wants_whatsapp_group)->toBeTrue();
})->with([
    'sans WhatsApp' => [null],
    // Le WhatsApp saisi (même invalide) est ignoré au profit du numéro identifié.
    'avec un autre WhatsApp' => [['country' => 'CI', 'number' => '0500000000']],
    'avec un WhatsApp invalide' => [['country' => 'ZZ', 'number' => 'abc']],
]);

it('accepte le groupe WhatsApp avec un numéro explicite', function (): void {
    $this->postVisit($this->tokenFor(), $this->visit1Answers([
        'wants_whatsapp_group' => true,
        'whatsapp' => ['country' => 'CI', 'number' => '0500000000'],
    ]))->assertCreated();

    expect(Visitor::query()->sole()->whatsapp)->toBe('+2250500000000');
});

it('accepte les champs non applicables à null, comme les envoie le client', function (): void {
    $this->postVisit($this->tokenFor(), [
        'full_name' => 'Aya Kouassi',
        'commune' => 'Cocody',
        'quartier' => 'Angré',
        'source' => 'passage',
        'source_other' => null,
        'invited_by' => null,
        'inviter_family_id' => null,
        'whatsapp' => null,
        'whatsapp_same_as_phone' => null,
        'wants_whatsapp_group' => false,
    ])->assertCreated();
});

it('accepte les champs facultatifs absents', function (): void {
    $this->postVisit($this->tokenFor(), [
        'full_name' => 'Aya Kouassi',
        'commune' => 'Cocody',
        'quartier' => 'Angré',
        'source' => 'saint_esprit',
    ])->assertCreated();

    $visitor = Visitor::query()->sole();

    expect($visitor->whatsapp)->toBeNull()->and($visitor->wants_whatsapp_group)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Étape 2
|--------------------------------------------------------------------------
*/

it('applique les règles de l\'étape 2', function (array $answers, string $field, string $message): void {
    $token = $this->secondStepToken();

    $this->postVisit($token, $answers, null)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => $message]);

    expect(Visit::query()->count())->toBe(1);
})->with([
    'raisons absentes' => [[], 'answers.return_reasons', 'Choisissez au moins une raison.'],
    'aucune raison' => [['return_reasons' => []], 'answers.return_reasons', 'Choisissez au moins une raison.'],
    'raisons non tableau' => [['return_reasons' => 'enseignement'], 'answers.return_reasons', 'Choisissez au moins une raison.'],
    'raisons en objet' => [['return_reasons' => ['a' => 'enseignement']], 'answers.return_reasons', 'Choix invalide.'],
    'raison inconnue' => [['return_reasons' => ['enseignement', 'musique']], 'answers.return_reasons.1', 'Choix invalide.'],
    'raison vide' => [['return_reasons' => ['']], 'answers.return_reasons.0', 'Choix invalide.'],
    'plus de 5 raisons' => [['return_reasons' => ['enseignement', 'accueil', 'autres', 'accueil', 'enseignement', 'autres'], 'return_reasons_other' => 'x'], 'answers.return_reasons', 'Choisissez au plus 5 raisons.'],
    'autres sans précision' => [['return_reasons' => ['autres']], 'answers.return_reasons_other', 'Précisez vos autres raisons.'],
    'précision trop longue' => [['return_reasons' => ['autres'], 'return_reasons_other' => str_repeat('a', 201)], 'answers.return_reasons_other', 'La précision ne doit pas dépasser 200 caractères.'],
]);

it('déduplique les raisons et ignore la précision sans « autres »', function (): void {
    $token = $this->secondStepToken();

    $this->postVisit($token, [
        'return_reasons' => ['accueil', 'enseignement', 'accueil'],
        'return_reasons_other' => 'ignorée',
    ], null)->assertCreated()->assertJsonPath('visit_number', 2);

    expect(Visit::query()->where('visit_number', 2)->sole()->answers)
        ->toBe(['return_reasons' => ['accueil', 'enseignement'], 'return_reasons_other' => null]);
});

it('ignore tout champ de profil aux étapes 2 et 3 (nom non modifiable)', function (): void {
    $token = $this->secondStepToken();

    $this->postVisit($token, [
        ...$this->visit2Answers(),
        'full_name' => 'Nom Usurpé',
        'commune' => 'Yopougon',
        'whatsapp' => ['country' => 'CI', 'number' => '0500000000'],
        'phone' => '+2250500000000',
    ], true)->assertCreated();

    $visitor = Visitor::query()->sole();

    expect($visitor->full_name)->toBe('Aya Kouassi')
        ->and($visitor->commune)->not->toBe('Yopougon')
        ->and($visitor->whatsapp)->toBeNull()
        ->and($visitor->phone)->toBe($this->e164)
        ->and(Visit::query()->where('visit_number', 2)->sole()->answers)
        ->toBe(['return_reasons' => ['enseignement'], 'return_reasons_other' => null]);
});

/*
|--------------------------------------------------------------------------
| Étape 3
|--------------------------------------------------------------------------
*/

it('applique les règles de l\'étape 3', function (array $answers, string $field, string $message): void {
    $token = $this->thirdStepToken();

    $this->postVisit($token, $answers, null)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => $message]);

    expect(Visit::query()->count())->toBe(2);
})->with([
    'motivation absente' => [[], 'answers.visit_reason', 'Choisissez la principale raison de vos visites.'],
    'motivation inconnue' => [['visit_reason' => 'curiosite'], 'answers.visit_reason', 'Choisissez la principale raison de vos visites.'],
    'motivation non textuelle' => [['visit_reason' => ['autres']], 'answers.visit_reason', 'Choisissez la principale raison de vos visites.'],
    'autres sans précision' => [['visit_reason' => 'autres', 'visit_reason_other' => ' '], 'answers.visit_reason_other', 'Précisez la raison de vos visites.'],
    'précision trop longue' => [['visit_reason' => 'autres', 'visit_reason_other' => str_repeat('a', 201)], 'answers.visit_reason_other', 'La précision ne doit pas dépasser 200 caractères.'],
]);

it('stocke la motivation et sa précision si « autres »', function (string $reason, ?string $other, ?string $expected): void {
    $token = $this->thirdStepToken();

    $this->postVisit($token, ['visit_reason' => $reason, 'visit_reason_other' => $other], null)
        ->assertCreated()
        ->assertJsonPath('completed', true);

    expect(Visit::query()->where('visit_number', 3)->sole()->answers)
        ->toBe(['visit_reason' => $reason, 'visit_reason_other' => $expected]);
})->with([
    'autres avec précision' => ['autres', '  Rapprochement familial ', 'Rapprochement familial'],
    'précision ignorée' => ['vacances', 'ignorée', null],
    'sans précision' => ['nouveau_resident', null, null],
]);

it('ignore le consentement aux étapes 2 et 3', function (): void {
    $token = $this->thirdStepToken();

    $this->postVisit($token, $this->visit3Answers(), false)->assertCreated();
});

it('refuse une clé d\'idempotence malformée même avec un jeton valide', function (): void {
    $this->postVisit($this->tokenFor(), $this->visit1Answers(), true, Str::random(36))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['idempotency_key']);
});
