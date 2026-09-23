<?php

use App\Http\Middleware\LimitPublicPayload;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Visit;
use App\Services\Journey\VisitAnswersValidator;
use App\Services\Journey\VisitTokenService;
use Illuminate\Support\Str;
use Tests\Feature\Public\Support\PublicJourney;

uses(PublicJourney::class);

/*
|--------------------------------------------------------------------------
| Bornes du corps des requêtes publiques (revue de sécurité, point 1)
|--------------------------------------------------------------------------
|
| Avant correctif, `answers.return_reasons.*` était développée pour CHAQUE élément d'un
| tableau non borné : 8 000 éléments = ~2 s de CPU (6,5 s avec des valeurs invalides) et un
| message d'erreur par élément, le tout SANS authentification. Le coût est désormais constant.
|
*/

beforeEach(function (): void {
    $this->seedFamilies();
    $this->travelToAbidjan('2026-09-22');
});

it('refuse 20 000 raisons en 422, avec un seul message et sans consommer le jeton', function (): void {
    $token = $this->secondStepToken();

    $start = hrtime(true);
    $response = $this->postVisit($token, ['return_reasons' => array_fill(0, 20000, 'a')], null)
        ->assertUnprocessable();
    $elapsed = (hrtime(true) - $start) / 1e6;

    expect($response->json('errors'))->toBe(['answers.return_reasons' => ['Les réponses au formulaire sont invalides.']])
        // Marge large : le trajet HTTP complet est mesuré ici, la mesure fine du coût CPU de
        // la validation est dans tests/Unit/Journey/VisitAnswersValidatorTest.php (< 100 ms).
        ->and($elapsed)->toBeLessThan(1000.0);

    // Le jeton reste utilisable : la personne peut corriger sa saisie.
    expect(app(VisitTokenService::class)->isConsumed(app(VisitTokenService::class)->decode($token)))->toBeFalse();

    $this->postVisit($token, $this->visit2Answers(), null)->assertCreated();
    expect(Visit::query()->count())->toBe(2);
});

it('accepte toujours le cas nominal de 5 raisons', function (): void {
    $token = $this->secondStepToken();

    $this->postVisit($token, [
        'return_reasons' => ['enseignement', 'chaleur_fraternelle', 'louange_adoration', 'accueil', 'autres'],
        'return_reasons_other' => 'La prière',
    ], null)->assertCreated()->assertJsonPath('visit_number', 2);

    expect(Visit::query()->where('visit_number', 2)->sole()->answers['return_reasons'])->toHaveCount(5);
});

it('refuse un corps volumineux (413), sans dépendre de la configuration PHP', function (): void {
    $body = (string) json_encode([
        'session_token' => 'x',
        'idempotency_key' => (string) Str::uuid(),
        'answers' => ['commune' => str_repeat('a', LimitPublicPayload::MAX_BYTES)],
    ]);

    expect(strlen($body))->toBeGreaterThan(LimitPublicPayload::MAX_BYTES);

    $this->call('POST', '/api/public/visits', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ], $body)
        ->assertStatus(413)
        ->assertExactJson(['message' => LimitPublicPayload::MESSAGE, 'code' => 'payload_too_large']);

    expect(Visit::query()->count())->toBe(0);
});

it('refuse une chaîne démesurée ou un JSON trop profond (413)', function (mixed $answers): void {
    $this->postJson('/api/public/visits', [
        'session_token' => 'x',
        'idempotency_key' => (string) Str::uuid(),
        'answers' => $answers,
    ])
        ->assertStatus(413)
        ->assertJsonPath('code', 'payload_too_large');
})->with([
    'chaîne trop longue' => [['commune' => str_repeat('a', LimitPublicPayload::MAX_STRING_LENGTH + 1)]],
    'JSON trop profond' => [array_reduce(range(1, LimitPublicPayload::MAX_DEPTH + 2), fn (mixed $carry): array => [$carry], 'x')],
]);

it('borne aussi l\'identification publique', function (): void {
    $this->postJson('/api/public/identify', [
        'country' => 'CI',
        'phone' => str_repeat('0', LimitPublicPayload::MAX_STRING_LENGTH + 1),
    ])->assertStatus(413)->assertJsonPath('code', 'payload_too_large');
});

it('déclare des bornes cohérentes avec le parcours réel', function (): void {
    // Un formulaire de visite 1 compte 10 champs ; la borne laisse une marge confortable.
    expect(VisitAnswersValidator::MAX_KEYS)->toBeGreaterThan(20)
        ->and(VisitAnswersValidator::MAX_ARRAY_ITEMS)->toBeGreaterThan(VisitAnswersValidator::MAX_RETURN_REASONS)
        // Un jeton de parcours réel fait environ 300 caractères.
        ->and(LimitPublicPayload::MAX_STRING_LENGTH)->toBeGreaterThan(2048);

    $this->postVisit($this->tokenFor(), $this->visit1Answers())->assertCreated();
});

it('rejette le corps AVANT le parcours du framework, en conservant les en-têtes de sécurité', function (): void {
    // TrimStrings parcourt tout le corps (mesuré : ~1,3 s pour 2,5 Mo, ~25 s pour 10 Mo) :
    // la borne doit donc passer avant lui, sans perdre les en-têtes du contrat §5.
    $body = (string) json_encode(['answers' => ['x' => array_fill(0, 600000, 'aa')]]);

    expect(strlen($body))->toBeGreaterThan(2 * 1024 * 1024);

    $start = hrtime(true);
    $response = $this->call('POST', '/api/public/visits', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ], $body);
    $elapsed = (hrtime(true) - $start) / 1e6;

    $response->assertStatus(413)
        ->assertJsonPath('code', 'payload_too_large')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', SecurityHeaders::CONTENT_SECURITY_POLICY);

    // Marge très large : sans la borne, ce seul corps coûterait plus d'une seconde de CPU.
    expect($elapsed)->toBeLessThan(500.0);
});
