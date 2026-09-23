<?php

use App\Support\RouteGroups;
use Illuminate\Http\Request;

beforeEach(function (): void {
    $probe = fn (Request $request) => ['has_session' => $request->hasSession()];

    testRoute(RouteGroups::PUBLIC_PREFIX, RouteGroups::PUBLIC_MIDDLEWARE, 'POST', '__stateless', $probe);
    testRoute(RouteGroups::AUTH_PREFIX, RouteGroups::AUTH_MIDDLEWARE, 'POST', '__stateful', $probe);
});

it('ne démarre aucune session et ne pose aucun cookie sur l\'API publique', function (): void {
    // Même une requête venant du SPA (domaine « stateful ») reste sans état.
    $response = $this->withHeaders(['Referer' => 'http://localhost/visite/1', 'Origin' => 'http://localhost'])
        ->postJson('/api/public/__stateless')
        ->assertOk()
        ->assertExactJson(['has_session' => false])
        ->assertHeaderMissing('Set-Cookie');

    expect($response->headers->getCookies())->toBe([]);
});

it('ignore les cookies envoyés à l\'API publique', function (): void {
    $this->withCookie('laravel_session', 'valeur-quelconque')
        ->withHeader('Referer', 'http://localhost/visite/1')
        ->postJson('/api/public/__stateless')
        ->assertOk()
        ->assertExactJson(['has_session' => false])
        ->assertHeaderMissing('Set-Cookie');
});

it('démarre une session (Sanctum SPA) sur les routes d\'authentification', function (): void {
    $response = $this->withHeader('Referer', 'http://localhost/admin/connexion')
        ->postJson('/api/auth/__stateful')
        ->assertOk()
        ->assertExactJson(['has_session' => true]);

    $names = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());

    expect($names)->toContain(config('session.cookie'))->toContain('XSRF-TOKEN');
});

it('ne démarre pas de session pour une origine non déclarée', function (): void {
    $this->withHeader('Referer', 'https://site-tiers.example/')
        ->postJson('/api/auth/__stateful')
        ->assertOk()
        ->assertExactJson(['has_session' => false]);
});
