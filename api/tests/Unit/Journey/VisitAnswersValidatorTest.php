<?php

use App\Enums\PhoneCountry;
use App\Enums\Source;
use App\Services\Journey\VisitAnswersValidator;
use App\Services\Journey\VisitSubmission;
use App\Services\Journey\VisitToken;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->validator = app(VisitAnswersValidator::class);
});

function journeyToken(int $step): VisitToken
{
    return new VisitToken('+2250700000000', PhoneCountry::CI, $step, str_repeat('a', 32), CarbonImmutable::now()->getTimestamp() + 900);
}

/**
 * Erreurs de validation (clé => premier message), ou [] si la validation passe.
 *
 * @return array<string, string>
 */
function journeyErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return array_map(fn (array $messages): string => $messages[0], $e->errors());
    }

    return [];
}

it('normalise le profil de la visite 1 et laisse les réponses vides', function (): void {
    $submission = $this->validator->validate(journeyToken(1), [
        'full_name' => '  Aya Kouassi  ',
        'commune' => 'Cocody',
        'quartier' => 'Angré',
        'source' => 'reseaux_sociaux',
        'source_other' => 'ignoré',
        'invited_by' => 'ignoré',
        'whatsapp' => ['country' => 'CI', 'number' => '05 00 00 00 00'],
        'wants_whatsapp_group' => true,
        'phone' => '+2250500000000',
        'status' => 'membre',
    ], true);

    expect($submission)->toBeInstanceOf(VisitSubmission::class)
        ->and($submission->step)->toBe(1)
        ->and($submission->answers)->toBe([])
        ->and($submission->profile)->toBe([
            'full_name' => 'Aya Kouassi',
            'commune' => 'Cocody',
            'quartier' => 'Angré',
            'source' => Source::ReseauxSociaux,
            'source_other' => null,
            'invited_by' => null,
            'inviter_family_id' => null,
            'whatsapp' => '+2250500000000',
            'wants_whatsapp_group' => true,
        ]);
});

it('reprend le numéro du jeton pour « même numéro »', function (): void {
    $submission = $this->validator->validate(journeyToken(1), [
        'full_name' => 'Aya', 'commune' => 'Cocody', 'quartier' => 'Angré', 'source' => 'passage',
        'whatsapp_same_as_phone' => true,
        'whatsapp' => ['country' => 'CI', 'number' => '0500000000'],
    ], true);

    expect($submission->profile['whatsapp'])->toBe('+2250700000000')
        ->and($submission->profile['wants_whatsapp_group'])->toBeFalse();
});

it('préfixe les erreurs par « answers. » et garde « consent » à part', function (): void {
    $errors = journeyErrors(fn () => $this->validator->validate(journeyToken(1), [
        'source' => 'autre',
        'wants_whatsapp_group' => true,
    ], false));

    expect($errors)->toBe([
        'consent' => 'Votre accord est nécessaire pour enregistrer votre visite.',
        'answers.full_name' => 'Indiquez votre nom et vos prénoms.',
        'answers.commune' => 'Indiquez votre commune.',
        'answers.quartier' => 'Indiquez votre quartier.',
        'answers.source_other' => "Précisez comment vous avez connu l'Église.",
        'answers.whatsapp.number' => 'Pour rejoindre le groupe WhatsApp, indiquez votre numéro WhatsApp ou cochez « même numéro ».',
    ]);
});

it('traite des réponses qui ne sont pas un objet comme vides', function (): void {
    expect(array_keys(journeyErrors(fn () => $this->validator->validate(journeyToken(2), 'texte', null))))
        ->toBe(['answers.return_reasons']);
});

it('normalise la visite 2 (raisons dédupliquées, précision seulement avec « autres »)', function (array $answers, array $expected): void {
    $submission = $this->validator->validate(journeyToken(2), [...$answers, 'full_name' => 'Ignoré'], null);

    expect($submission->step)->toBe(2)
        ->and($submission->profile)->toBe([])
        ->and($submission->answers)->toBe($expected);
})->with([
    'une raison' => [['return_reasons' => ['accueil']], ['return_reasons' => ['accueil'], 'return_reasons_other' => null]],
    'doublons' => [
        ['return_reasons' => ['autres', 'accueil', 'autres'], 'return_reasons_other' => ' La prière '],
        ['return_reasons' => ['autres', 'accueil'], 'return_reasons_other' => 'La prière'],
    ],
    'précision sans « autres »' => [
        ['return_reasons' => ['enseignement'], 'return_reasons_other' => 'ignorée'],
        ['return_reasons' => ['enseignement'], 'return_reasons_other' => null],
    ],
    'toutes les raisons' => [
        ['return_reasons' => ['enseignement', 'chaleur_fraternelle', 'louange_adoration', 'accueil', 'autres'], 'return_reasons_other' => 'x'],
        ['return_reasons' => ['enseignement', 'chaleur_fraternelle', 'louange_adoration', 'accueil', 'autres'], 'return_reasons_other' => 'x'],
    ],
]);

it('normalise la visite 3', function (array $answers, array $expected): void {
    $submission = $this->validator->validate(journeyToken(3), $answers, true);

    expect($submission->step)->toBe(3)->and($submission->answers)->toBe($expected);
})->with([
    'motivation simple' => [['visit_reason' => 'vacances', 'visit_reason_other' => 'ignorée'], ['visit_reason' => 'vacances', 'visit_reason_other' => null]],
    'autres' => [['visit_reason' => 'autres', 'visit_reason_other' => "\u{200B} Mariage "], ['visit_reason' => 'autres', 'visit_reason_other' => 'Mariage']],
]);

it('applique les longueurs maximales en caractères (et non en octets)', function (): void {
    $ok = fn (int $length) => journeyErrors(fn () => $this->validator->validate(journeyToken(3), [
        'visit_reason' => 'autres', 'visit_reason_other' => str_repeat('é', $length),
    ], null));

    expect($ok(200))->toBe([])
        ->and($ok(201))->toBe(['answers.visit_reason_other' => 'La précision ne doit pas dépasser 200 caractères.']);
});

/*
|--------------------------------------------------------------------------
| Bornes de taille : épuisement CPU non authentifié (revue de sécurité)
|--------------------------------------------------------------------------
|
| Avant correctif, `answers.return_reasons.*` était développée pour CHAQUE élément même
| quand `max:5` avait déjà échoué : 8 000 éléments coûtaient environ 2 s de CPU (6,5 s avec
| des valeurs invalides) et produisaient un message d'erreur par élément.
|
*/

it('refuse un tableau démesuré à coût constant, avec un seul message', function (string $value): void {
    $answers = ['return_reasons' => array_fill(0, 20000, $value)];

    $start = hrtime(true);
    $errors = journeyErrors(fn () => $this->validator->validate(journeyToken(2), $answers, null));
    $elapsed = (hrtime(true) - $start) / 1e6;

    expect($errors)->toBe(['answers.return_reasons' => 'Les réponses au formulaire sont invalides.'])
        ->and($elapsed)->toBeLessThan(100.0);
})->with([
    'valeurs valides' => ['enseignement'],
    // Le cas amplifié : une erreur par élément était renvoyée au client.
    'valeurs invalides' => ['valeur-inconnue'],
]);

it('borne chaque tableau, le nombre total de clés et la profondeur', function (mixed $answers, string $key): void {
    expect(journeyErrors(fn () => $this->validator->validate(journeyToken(1), $answers, true)))
        ->toBe([$key => 'Les réponses au formulaire sont invalides.']);
})->with([
    // 20 champs (la borne par tableau) contenant chacun 3 valeurs : 80 clés au total.
    'trop de clés au total' => [
        array_combine(
            array_map(static fn (int $i): string => "champ_{$i}", range(1, VisitAnswersValidator::MAX_ARRAY_ITEMS)),
            array_fill(0, VisitAnswersValidator::MAX_ARRAY_ITEMS, ['a', 'b', 'c']),
        ),
        'answers',
    ],
    'objet racine trop grand' => [
        array_combine(
            array_map(static fn (int $i): string => "champ_{$i}", range(1, VisitAnswersValidator::MAX_ARRAY_ITEMS + 1)),
            array_fill(0, VisitAnswersValidator::MAX_ARRAY_ITEMS + 1, 'x'),
        ),
        'answers',
    ],
    'tableau imbriqué trop grand' => [
        ['whatsapp' => array_fill(0, VisitAnswersValidator::MAX_ARRAY_ITEMS + 1, 'x')],
        'answers.whatsapp',
    ],
    'trop profond' => [['whatsapp' => ['number' => ['trop' => ['profond']]]], 'answers.whatsapp.number.trop'],
]);

it('laisse passer les tailles normales et conserve le message métier jusqu\'à la borne', function (): void {
    // 6 raisons : la borne (20) n'intervient pas, le message métier reste celui du contrat.
    expect(journeyErrors(fn () => $this->validator->validate(journeyToken(2), [
        'return_reasons' => ['enseignement', 'accueil', 'autres', 'accueil', 'enseignement', 'autres'],
        'return_reasons_other' => 'x',
    ], null)))->toBe(['answers.return_reasons' => 'Choisissez au plus 5 raisons.']);

    // Cas nominal à 5 valeurs : toujours accepté.
    $submission = $this->validator->validate(journeyToken(2), [
        'return_reasons' => ['enseignement', 'chaleur_fraternelle', 'louange_adoration', 'accueil', 'autres'],
        'return_reasons_other' => 'La prière',
    ], null);

    expect($submission->answers['return_reasons'])->toHaveCount(5);
});

it('ne renvoie qu\'un seul message par étape 2 ou 3 (pas d\'amplification)', function (): void {
    $errors = journeyErrors(fn () => $this->validator->validate(journeyToken(2), [
        'return_reasons' => array_fill(0, VisitAnswersValidator::MAX_ARRAY_ITEMS, 'valeur-inconnue'),
    ], null));

    expect($errors)->toHaveCount(1);
});
