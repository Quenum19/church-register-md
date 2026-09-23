<?php

use App\Enums\PhoneCountry;
use App\Exceptions\ApiException;
use App\Services\Journey\VisitToken;
use App\Services\Journey\VisitTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-22 10:00', 'Africa/Abidjan'));
    $this->tokens = app(VisitTokenService::class);
});

/**
 * @param  array<string, mixed>  $payload
 */
function journeyEncryptedPayload(array $payload): string
{
    return Crypt::encryptString(json_encode($payload));
}

/**
 * @return array<string, mixed>
 */
function journeyTokenPayload(array $overrides = []): array
{
    return array_replace([
        'phone_e164' => '+2250700000000',
        'country' => 'CI',
        'step' => 2,
        'jti' => str_repeat('ab', 16),
        'exp' => CarbonImmutable::now()->getTimestamp() + 900,
    ], $overrides);
}

function journeyExpectApiError(Closure $callback, string $code, int $status): void
{
    try {
        $callback();
    } catch (ApiException $e) {
        expect($e->errorCode)->toBe($code)->and($e->status)->toBe($status);

        return;
    }

    test()->fail("ApiException {$code} attendue.");
}

it('émet un jeton opaque et le relit', function (): void {
    $token = $this->tokens->issue('+2250700000000', PhoneCountry::CI, 2);

    expect($token)->not->toContain('2250700000000');

    $decoded = $this->tokens->decode($token);

    expect($decoded)->toBeInstanceOf(VisitToken::class)
        ->and($decoded->phoneE164)->toBe('+2250700000000')
        ->and($decoded->country)->toBe(PhoneCountry::CI)
        ->and($decoded->step)->toBe(2)
        ->and($decoded->jti)->toMatch('/^[0-9a-f]{32}$/')
        ->and($decoded->expiresAt)->toBe(CarbonImmutable::now()->getTimestamp() + VisitTokenService::TTL_SECONDS)
        ->and(VisitTokenService::TTL_SECONDS)->toBe(900);
});

it('chiffre exactement { phone_e164, country, step, jti, exp }', function (): void {
    $payload = json_decode(Crypt::decryptString($this->tokens->issue('+33612345678', PhoneCountry::FR, 1)), true);

    expect(array_keys($payload))->toBe(['phone_e164', 'country', 'step', 'jti', 'exp']);
});

it('n\'émet de jeton que pour les étapes 1 à 3', function (int $step): void {
    $this->tokens->issue('+2250700000000', PhoneCountry::CI, $step);
})->with([0, 4])->throws(InvalidArgumentException::class);

it('expire au bout de 15 minutes', function (): void {
    $token = $this->tokens->issue('+2250700000000', PhoneCountry::CI, 1);

    $this->travel(899)->seconds();
    expect($this->tokens->decode($token)->step)->toBe(1);

    $this->travel(1)->seconds();
    journeyExpectApiError(fn () => $this->tokens->decode($token), 'token_expired', 410);

    // Le rejeu idempotent ignore l'expiration.
    expect($this->tokens->decode($token, ignoreExpiry: true)->phoneE164)->toBe('+2250700000000');
});

it('refuse un jeton falsifié, étranger ou au contenu inattendu (401 token_invalid)', function (Closure $token): void {
    journeyExpectApiError(fn () => $this->tokens->decode($token()), 'token_invalid', 401);
    journeyExpectApiError(fn () => $this->tokens->decode($token(), ignoreExpiry: true), 'token_invalid', 401);
})->with([
    'vide' => [fn (): string => ''],
    'texte clair' => [fn (): string => json_encode(journeyTokenPayload())],
    'base64 quelconque' => [fn (): string => base64_encode('{"iv":"x","value":"y","mac":"z"}')],
    'caractère modifié' => [function (): string {
        $token = base64_decode(app(VisitTokenService::class)->issue('+2250700000000', PhoneCountry::CI, 1));
        $data = json_decode($token, true);
        $data['value'] = strrev($data['value']);

        return base64_encode(json_encode($data));
    }],
    'autre APP_KEY' => [fn (): string => (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encryptString(json_encode(journeyTokenPayload()))],
    'JSON invalide' => [fn (): string => Crypt::encryptString('{pas du json')],
    'pas un objet' => [fn (): string => journeyEncryptedPayload(['+2250700000000'])],
    'champ manquant' => [fn (): string => journeyEncryptedPayload(array_diff_key(journeyTokenPayload(), ['jti' => true]))],
    'numéro non E.164' => [fn (): string => journeyEncryptedPayload(journeyTokenPayload(['phone_e164' => '0700000000']))],
    'pays inconnu' => [fn (): string => journeyEncryptedPayload(journeyTokenPayload(['country' => 'ZZ']))],
    'étape textuelle' => [fn (): string => journeyEncryptedPayload(journeyTokenPayload(['step' => '2']))],
    'étape 4' => [fn (): string => journeyEncryptedPayload(journeyTokenPayload(['step' => 4]))],
    'étape « complete »' => [fn (): string => journeyEncryptedPayload(journeyTokenPayload(['step' => 'complete']))],
    'jti invalide' => [fn (): string => journeyEncryptedPayload(journeyTokenPayload(['jti' => 'abc']))],
    'expiration textuelle' => [fn (): string => journeyEncryptedPayload(journeyTokenPayload(['exp' => 'demain']))],
    'sérialisation PHP' => [fn (): string => Crypt::encrypt(journeyTokenPayload())],
]);

it('accepte un jeton correctement formé', function (): void {
    expect($this->tokens->decode(journeyEncryptedPayload(journeyTokenPayload()))->step)->toBe(2);
});

it('marque un jeton consommé une seule fois, de façon atomique', function (): void {
    $token = $this->tokens->decode($this->tokens->issue('+2250700000000', PhoneCountry::CI, 1));

    expect($this->tokens->isConsumed($token))->toBeFalse()
        ->and($this->tokens->markConsumed($token))->toBeTrue()
        ->and($this->tokens->isConsumed($token))->toBeTrue()
        ->and($this->tokens->markConsumed($token))->toBeFalse();

    // Un autre jeton du même numéro reste utilisable.
    $other = $this->tokens->decode($this->tokens->issue('+2250700000000', PhoneCountry::CI, 1));
    expect($this->tokens->isConsumed($other))->toBeFalse();
});

it('garde le jeton consommé au-delà de son expiration (marge de 5 minutes)', function (): void {
    $token = $this->tokens->decode($this->tokens->issue('+2250700000000', PhoneCountry::CI, 1));
    $this->tokens->markConsumed($token);

    $this->travel(15 * 60 + VisitTokenService::CONSUMED_MARGIN_SECONDS - 1)->seconds();
    expect($this->tokens->isConsumed($token))->toBeTrue();

    $this->travel(2)->seconds();
    expect($this->tokens->isConsumed($token))->toBeFalse();
});

it('garde le marqueur même si le jeton est consommé juste avant son expiration', function (): void {
    $token = $this->tokens->decode($this->tokens->issue('+2250700000000', PhoneCountry::CI, 1));

    $this->travel(15)->minutes();

    expect($this->tokens->markConsumed($token))->toBeTrue()
        ->and($this->tokens->isConsumed($token))->toBeTrue();
});
