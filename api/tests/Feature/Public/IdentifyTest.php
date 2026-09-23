<?php

use App\Enums\PhoneCountry;
use App\Models\Member;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\Journey\VisitTokenService;
use App\Services\VisitorStatusService;
use App\Support\RateLimits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Tests\Feature\Public\Support\PublicJourney;

uses(PublicJourney::class);

beforeEach(function (): void {
    $this->seedFamilies();
    $this->travelToAbidjan('2026-09-22');
});

it('renvoie l\'étape 1 et un jeton de 15 minutes pour un numéro inconnu', function (): void {
    $response = $this->identify()->assertOk();

    $this->assertStateless($response);

    expect(array_keys($response->json()))->toBe(['step', 'session_token', 'expires_in'])
        ->and($response->json('step'))->toBe(1)
        ->and($response->json('session_token'))->toBeString()
        ->and($response->json('expires_in'))->toBe(900);

    // Aucun visiteur n'est créé par l'identification.
    expect(Visitor::query()->count())->toBe(0);
});

it('place dans le jeton chiffré le numéro E.164, le pays, l\'étape, un jti et l\'expiration', function (): void {
    $token = $this->tokenFor();

    // Opaque : le numéro n'apparaît pas en clair.
    expect($token)->not->toContain('0700000000')->not->toContain('2250700000000');

    $payload = json_decode(Crypt::decryptString($token), true);

    expect($payload)->toHaveKeys(['phone_e164', 'country', 'step', 'jti', 'exp'])
        ->and($payload['phone_e164'])->toBe('+2250700000000')
        ->and($payload['country'])->toBe('CI')
        ->and($payload['step'])->toBe(1)
        ->and($payload['jti'])->toMatch('/^[0-9a-f]{32}$/')
        ->and($payload['exp'])->toBe(CarbonImmutable::now()->getTimestamp() + 900);

    // Chaque identification émet un jeton distinct.
    $other = json_decode(Crypt::decryptString($this->tokenFor()), true);
    expect($other['jti'])->not->toBe($payload['jti']);
});

it('renvoie l\'étape suivante selon le nombre de visites', function (int $visits, int|string $expected): void {
    Visitor::factory()->withVisits($visits, CarbonImmutable::parse('2026-05-03'))->create(['phone' => $this->e164]);

    $response = $this->identify()->assertOk()->assertJsonPath('step', $expected);

    if (is_int($expected)) {
        $response->assertJsonPath('expires_in', 900);
        expect($response->json('session_token'))->toBeString();
    } else {
        $response->assertExactJson(['step' => $expected, 'session_token' => null, 'expires_in' => null]);
    }
})->with([
    'prospect → 2' => [1, 2],
    'récurrent → 3' => [2, 3],
    'trois visites → complete' => [3, 'complete'],
]);

it('renvoie « complete » pour un membre converti', function (): void {
    Visitor::factory()->member()->create(['phone' => $this->e164]);

    $this->identify()->assertOk()->assertExactJson(['step' => 'complete', 'session_token' => null, 'expires_in' => null]);
});

it('renvoie « complete » pour un membre, même avec moins de trois visites', function (): void {
    $visitor = Visitor::factory()->prospect(CarbonImmutable::parse('2026-08-02'))->create(['phone' => $this->e164]);
    Member::query()->create(['visitor_id' => $visitor->id, 'converted_by' => null, 'converted_at' => now()]);

    $this->identify()->assertOk()->assertJsonPath('step', 'complete')->assertJsonPath('session_token', null);
});

it('renvoie « done_today » quand une visite existe déjà aujourd\'hui (fuseau Africa/Abidjan)', function (): void {
    Visitor::factory()->prospect(CarbonImmutable::parse('2026-09-22'))->create(['phone' => $this->e164]);

    $this->identify()->assertOk()->assertExactJson(['step' => 'done_today', 'session_token' => null, 'expires_in' => null]);

    // 23 h 59 à Abidjan : toujours le même jour (même si Paris est déjà le lendemain).
    $this->travelTo(CarbonImmutable::parse('2026-09-22 23:59', 'Africa/Abidjan'));
    $this->identify()->assertJsonPath('step', 'done_today');

    // Le lendemain à Abidjan : 2e visite.
    $this->travelTo(CarbonImmutable::parse('2026-09-23 00:01', 'Africa/Abidjan'));
    $this->identify()->assertJsonPath('step', 2);
});

it('préfère « done_today » à « complete » le jour de la 3e visite', function (): void {
    $visitor = Visitor::factory()->withVisits(2, CarbonImmutable::parse('2026-09-06'))->create(['phone' => $this->e164]);
    Visit::factory()->third()->for($visitor)->onDate('2026-09-22')->create();
    app(VisitorStatusService::class)->refresh($visitor);

    $this->identify()->assertJsonPath('step', 'done_today');

    $this->travelToAbidjan('2026-09-29');
    $this->identify()->assertJsonPath('step', 'complete');
});

it('normalise le numéro : plusieurs saisies désignent le même visiteur', function (string $country, string $phone): void {
    Visitor::factory()->prospect(CarbonImmutable::parse('2026-09-13'))->create(['phone' => '+2250700000000']);

    $token = $this->identify($phone, $country)->assertOk()->assertJsonPath('step', 2)->json('session_token');

    expect(app(VisitTokenService::class)->decode($token)->phoneE164)->toBe('+2250700000000');
})->with([
    'CI avec espaces' => ['CI', '07 00 00 00 00'],
    'CI chiffres seuls' => ['CI', '0700000000'],
    'CI avec indicatif' => ['CI', '+2250700000000'],
    'CI avec indicatif et espaces' => ['CI', '+225 07 00 00 00 00'],
    'autre pays' => ['OTHER', '+2250700000000'],
]);

it('accepte un numéro étranger (pays de la liste ou OTHER)', function (string $country, string $phone, string $e164): void {
    $token = $this->identify($phone, $country)->assertOk()->assertJsonPath('step', 1)->json('session_token');

    $decoded = app(VisitTokenService::class)->decode($token);

    expect($decoded->phoneE164)->toBe($e164)
        ->and($decoded->country)->toBe(PhoneCountry::from($country));
})->with([
    'France' => ['FR', '06 12 34 56 78', '+33612345678'],
    'Sénégal' => ['SN', '77 123 45 67', '+221771234567'],
    'Royaume-Uni (OTHER)' => ['OTHER', '+447911123456', '+447911123456'],
]);

it('refuse un numéro invalide avec une 422 sur phone, sans renvoyer la saisie', function (mixed $phone): void {
    $response = $this->postJson('/api/public/identify', ['country' => 'CI', 'phone' => $phone])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation')
        ->assertJsonValidationErrors(['phone'])
        ->assertJsonMissingValidationErrors(['country']);

    $this->assertStateless($response);

    if (is_string($phone) && $phone !== '') {
        expect($response->getContent())->not->toContain($phone);
    }
})->with([
    'trop court' => ['0700'],
    'lettres' => ['07abc00000'],
    'CI sans le 0 initial' => ['700000000'],
    'vide' => [''],
    'trop long' => [str_repeat('0', 33)],
    'tableau' => [['0700000000']],
]);

it('refuse un pays inconnu avec une 422 sur country uniquement', function (mixed $country): void {
    $this->postJson('/api/public/identify', ['country' => $country, 'phone' => '0700000000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['country'])
        ->assertJsonMissingValidationErrors(['phone']);
})->with([
    'code inconnu' => ['ZZ'],
    'minuscules' => ['ci'],
    'absent' => [null],
]);

it('exige l\'indicatif pour « Autre pays »', function (): void {
    $this->identify('0700000000', 'OTHER')->assertUnprocessable()->assertJsonValidationErrors(['phone']);
});

it('rédige les erreurs de validation en français', function (): void {
    $this->postJson('/api/public/identify', ['country' => 'CI', 'phone' => '0700'])
        ->assertJsonPath('errors.phone.0', 'Le numéro de téléphone est invalide.');

    $this->postJson('/api/public/identify', ['country' => 'CI'])
        ->assertJsonPath('errors.phone.0', 'Saisissez votre numéro de téléphone.');
});

it('limite l\'identification à 5 par heure et par numéro, quel que soit le format', function (): void {
    foreach (['07 00 00 00 00', '0700000000', '+2250700000000', '+225 07 00 00 00 00', '07 00 00 00 00'] as $index => $phone) {
        $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$index}"])->identify($phone)->assertOk();
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
        ->identify('0700000000')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'too_many_requests');

    expect(array_keys($response->json()))->toBe(['message', 'code'])
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(3600);

    // Un autre numéro, depuis la même IP, n'est pas bloqué.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])->identify('05 00 00 00 00')->assertOk();

    // Une heure plus tard, le numéro peut de nouveau s'identifier.
    $this->travel(61)->minutes();
    $this->identify()->assertOk();

    expect(RateLimits::IDENTIFY_PER_HOUR)->toBe(5);
});

/*
|--------------------------------------------------------------------------
| Oracle de présence : limite par IP et décompte des jetons (revue de sécurité)
|--------------------------------------------------------------------------
*/

it('ne divulgue jamais l\'état des compteurs de débit', function (): void {
    $headers = ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'];

    $ok = $this->identify()->assertOk();

    foreach ($headers as $header) {
        $ok->assertHeaderMissing($header);
    }

    foreach (range(1, 4) as $ignored) {
        $this->identify()->assertOk();
    }

    // Y compris sur un refus : seul Retry-After, utile au client légitime, subsiste.
    $limited = $this->identify()->assertTooManyRequests()->assertHeader('Retry-After');

    foreach ($headers as $header) {
        $limited->assertHeaderMissing($header);
    }
});

it('limite les identifications par IP, quel que soit le numéro essayé', function (): void {
    config(['rate-limits.identify_per_ip_per_hour' => 10]);

    foreach (range(1, 10) as $n) {
        $this->identify(sprintf('07%08d', $n))->assertOk()->assertJsonPath('step', 1);
    }

    // 11e numéro depuis la même IP : refusé, alors qu'aucun numéro n'a atteint sa propre limite.
    $this->identify(sprintf('07%08d', 11))
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'too_many_requests');

    // Depuis une autre IP, le même numéro passe : la limite vise bien l'appelant.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
        ->identify(sprintf('07%08d', 11))
        ->assertOk();
});

it('laisse passer 120 identifications depuis une même IP (dimanche chargé au Wi-Fi de l\'Église)', function (): void {
    foreach (range(1, 120) as $n) {
        $this->identify(sprintf('07%08d', $n))->assertOk()->assertJsonPath('step', 1);
    }
})->group('slow');

it('ne compte dans la limite par numéro que les identifications qui délivrent un jeton', function (): void {
    $visitor = Visitor::factory()->prospect(CarbonImmutable::parse('2026-09-22'))->create(['phone' => $this->e164]);

    // Huit essais sans jeton (visite du jour déjà enregistrée) : rien n'est décompté.
    foreach (range(1, 8) as $ignored) {
        $this->identify()->assertOk()->assertJsonPath('step', 'done_today');
    }

    // La visite du jour est retirée côté admin : le quota du numéro est resté intact.
    Visit::query()->where('visitor_id', $visitor->id)->delete();

    foreach (range(1, RateLimits::IDENTIFY_PER_HOUR) as $ignored) {
        expect($this->identify()->assertOk()->json('session_token'))->toBeString();
    }

    // Les 5 jetons de l'heure sont consommés : le 6e est refusé.
    $this->identify()->assertTooManyRequests()->assertJsonPath('code', 'too_many_requests');
});
