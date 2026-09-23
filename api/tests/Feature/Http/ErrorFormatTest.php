<?php

use App\Exceptions\ApiException;
use App\Models\Visitor;
use App\Support\RouteGroups;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

beforeEach(function (): void {
    $public = fn (string $method, string $uri, Closure $action) => testRoute(RouteGroups::PUBLIC_PREFIX, RouteGroups::PUBLIC_MIDDLEWARE, $method, $uri, $action);

    $public('POST', '__errors/validation', fn (Request $request) => $request->validate([
        'full_name' => ['required', 'string', 'max:100'],
        'commune' => ['required'],
    ]));
    $public('GET', '__errors/visitors/{visitor}', fn (Visitor $visitor) => ['id' => $visitor->id]);
    $public('GET', '__errors/forbidden', fn () => throw new AuthorizationException);
    $public('GET', '__errors/forbidden-message', fn () => throw new AuthorizationException('Seul l\'auteur peut supprimer cette note.'));
    $public('GET', '__errors/csrf', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
    $public('GET', '__errors/business', fn () => throw new ApiException('Une visite existe déjà aujourd\'hui.', 'already_today', 409));
    $public('GET', '__errors/crash', fn () => throw new RuntimeException('SQLSTATE secret : mot de passe root'));
});

it('rend les erreurs de validation (422)', function (): void {
    $this->postJson('/api/public/__errors/validation', ['full_name' => str_repeat('a', 101)])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation')
        ->assertJsonPath('errors.commune.0', 'Le champ commune est obligatoire.')
        ->assertJsonStructure(['message', 'code', 'errors' => ['full_name', 'commune']]);
});

it('rend 404 pour un modèle introuvable ou un identifiant invalide', function (string $id): void {
    $this->getJson("/api/public/__errors/visitors/{$id}")
        ->assertNotFound()
        ->assertExactJson(['message' => 'Ressource introuvable.', 'code' => 'not_found']);
})->with(['999999', 'abc']);

it('rend 401 unauthenticated pour un invité sur l\'API admin', function (): void {
    testRoute(RouteGroups::ADMIN_PREFIX, RouteGroups::ADMIN_MIDDLEWARE, 'GET', '__errors/admin', fn () => ['ok' => true]);

    // Même sans en-tête Accept JSON : jamais de redirection vers une page de connexion.
    $this->get('/api/admin/__errors/admin')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Non authentifié.', 'code' => 'unauthenticated']);
});

it('rend 403 forbidden, avec le message métier s\'il existe', function (): void {
    $this->getJson('/api/public/__errors/forbidden')
        ->assertForbidden()
        ->assertExactJson(['message' => "Vous n'avez pas l'autorisation d'effectuer cette action.", 'code' => 'forbidden']);

    $this->getJson('/api/public/__errors/forbidden-message')
        ->assertForbidden()
        ->assertJsonPath('message', "Seul l'auteur peut supprimer cette note.");
});

it('rend 419 csrf_expired', function (): void {
    $this->getJson('/api/public/__errors/csrf')
        ->assertStatus(419)
        ->assertJsonPath('code', 'csrf_expired');
});

it('rend 405 pour un verbe non autorisé', function (): void {
    $this->deleteJson('/api/health')
        ->assertStatus(405)
        ->assertJsonPath('code', 'method_not_allowed');
});

it('rend les erreurs métier ApiException', function (): void {
    $this->getJson('/api/public/__errors/business')
        ->assertStatus(409)
        ->assertExactJson(['message' => "Une visite existe déjà aujourd'hui.", 'code' => 'already_today']);
});

it('rend 500 sans aucun détail technique, même avec APP_DEBUG en production', function (): void {
    config(['app.debug' => true]);
    app()->detectEnvironment(fn (): string => 'production');

    $response = $this->getJson('/api/public/__errors/crash')
        ->assertStatus(500)
        ->assertExactJson([
            'message' => 'Une erreur interne est survenue. Veuillez réessayer plus tard.',
            'code' => 'server_error',
        ]);

    expect($response->getContent())->not->toContain('SQLSTATE')->not->toContain('trace');
});

it('rend 500 générique hors local même sans en-tête JSON', function (): void {
    $this->get('/api/public/__errors/crash')
        ->assertStatus(500)
        ->assertJsonPath('code', 'server_error');
});

it('laisse la page de débogage en local avec APP_DEBUG', function (): void {
    config(['app.debug' => true]);
    app()->detectEnvironment(fn (): string => 'local');

    $this->getJson('/api/public/__errors/crash')
        ->assertStatus(500)
        ->assertJsonPath('message', 'SQLSTATE secret : mot de passe root');
});
