<?php

use App\Models\User;
use App\Support\RateLimits;
use App\Support\RouteGroups;
use Illuminate\Http\Request;

beforeEach(function (): void {
    testRoute(RouteGroups::PUBLIC_PREFIX, RouteGroups::PUBLIC_MIDDLEWARE, 'GET', '__ip', fn (Request $request) => ['ip' => $request->ip()]);
    testRoute(RouteGroups::PUBLIC_PREFIX, RouteGroups::PUBLIC_MIDDLEWARE, 'POST', '__identify', fn () => ['ok' => true]);
    Route::middleware(['public', 'throttle:identify'])->post('api/public/__identify-limited', fn () => ['ok' => true]);
    Route::middleware(['api', 'throttle:login'])->post('api/auth/__login', fn () => ['ok' => true]);
    testRoute(RouteGroups::ADMIN_PREFIX, RouteGroups::ADMIN_MIDDLEWARE, 'GET', '__admin', fn () => ['ok' => true]);
});

it('limite l\'API publique à 300 requêtes par minute et par IP', function (): void {
    $this->getJson('/api/public/__ip')
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', (string) RateLimits::PUBLIC_PER_MINUTE)
        ->assertHeader('X-RateLimit-Remaining', (string) (RateLimits::PUBLIC_PER_MINUTE - 1));
});

it('limite l\'identification par IP, quel que soit le numéro essayé', function (): void {
    // Valeur abaissée pour le test : la limite réelle est configurable (RATE_LIMIT_IDENTIFY_PER_IP).
    config(['rate-limits.identify_per_ip_per_hour' => 10]);

    foreach (range(1, 10) as $n) {
        $this->postJson('/api/public/__identify-limited', ['country' => 'CI', 'phone' => "07 00 00 00 {$n}0"])
            ->assertOk();
    }

    $this->postJson('/api/public/__identify-limited', ['country' => 'CI', 'phone' => '05 00 00 00 00'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'too_many_requests');

    // Une autre IP n'est pas concernée : l'oracle de présence est fermé sans punir le Wi-Fi voisin.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
        ->postJson('/api/public/__identify-limited', ['country' => 'CI', 'phone' => '05 00 00 00 00'])
        ->assertOk();
});

it('applique par défaut 200 identifications par heure et par IP', function (): void {
    expect(RateLimits::identifyPerIpPerHour())->toBe(200)
        ->and(RateLimits::IDENTIFY_PER_IP_PER_HOUR)->toBe(200)
        // Un dimanche chargé (plus de 100 identifications en une heure) passe largement.
        ->and(RateLimits::identifyPerIpPerHour())->toBeGreaterThan(120);

    config(['rate-limits.identify_per_ip_per_hour' => 350]);
    expect(RateLimits::identifyPerIpPerHour())->toBe(350);
});

it('fournit des clés de limitation sans donnée personnelle en clair', function (): void {
    $key = RateLimits::identifyKey('CI', '07 00 00 00 00');

    expect($key)->toStartWith('identify|')
        ->not->toContain('0700000000')
        ->and(RateLimits::identifyKey('CI', '+2250700000000'))->toBe($key)
        ->and(RateLimits::identifyKey('CI', 'abc'))->toBeNull()
        ->and(RateLimits::loginKey('Admin@Exemple.org ', '1.2.3.4'))->toBe(RateLimits::loginKey('admin@exemple.org', '1.2.3.4'))
        ->and(RateLimits::loginKey('admin@exemple.org', '1.2.3.4'))->not->toContain('admin@exemple.org');
});

it('limite la connexion à 5 tentatives / 15 min par e-mail (insensible à la casse) et IP', function (): void {
    foreach (['admin@exemple.org', 'ADMIN@exemple.org', 'Admin@Exemple.org', 'admin@exemple.org', 'admin@exemple.org'] as $email) {
        $this->postJson('/api/auth/__login', ['email' => $email])->assertOk();
    }

    $this->postJson('/api/auth/__login', ['email' => 'admin@exemple.org'])->assertTooManyRequests();

    // Autre IP ou autre e-mail : compteur distinct.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->postJson('/api/auth/__login', ['email' => 'admin@exemple.org'])->assertOk();
    $this->postJson('/api/auth/__login', ['email' => 'autre@exemple.org'])->assertOk();

    // La fenêtre est de 15 minutes.
    $this->travel(16)->minutes();
    $this->postJson('/api/auth/__login', ['email' => 'admin@exemple.org'])->assertOk();
});

it('limite l\'API admin à 120 requêtes par minute et par utilisateur', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/admin/__admin')
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', (string) RateLimits::ADMIN_PER_MINUTE);
});

it('ignore X-Forwarded-For sans proxy de confiance', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeader('X-Forwarded-For', '203.0.113.9')
        ->getJson('/api/public/__ip')
        ->assertExactJson(['ip' => '10.0.0.1']);
});

it('utilise X-Forwarded-For quand le proxy est déclaré dans TRUSTED_PROXIES', function (): void {
    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeader('X-Forwarded-For', '203.0.113.9')
        ->getJson('/api/public/__ip')
        ->assertExactJson(['ip' => '203.0.113.9']);

    // Un appelant hors de la plage déclarée ne peut pas usurper une IP.
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])
        ->withHeader('X-Forwarded-For', '203.0.113.9')
        ->getJson('/api/public/__ip')
        ->assertExactJson(['ip' => '192.0.2.1']);
});
