<?php

use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->publicPath = storage_path('framework/testing/public-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($this->publicPath);
    app()->usePublicPath($this->publicPath);
});

afterEach(function (): void {
    File::deleteDirectory($this->publicPath);
});

function buildSpa(string $publicPath): void
{
    File::put($publicPath.'/spa.html', '<!doctype html><html lang="fr"><title>Church Register</title><div id="root"></div></html>');
}

it('renvoie spa.html pour les routes du SPA', function (string $uri): void {
    buildSpa($this->publicPath);

    $response = $this->get($uri);

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeaderMissing('Set-Cookie');

    expect($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($response->baseResponse->getFile()->getFilename())->toBe('spa.html');
})->with(['/', '/visite/1', '/visite/merci', '/admin', '/admin/visiteurs/12', '/qrcode', '/Admin/Connexion']);

it('répond 404 aux fichiers, chemins cachés et dossiers de l\'application', function (string $uri): void {
    buildSpa($this->publicPath);

    $this->get($uri)->assertNotFound()->assertSee('Page introuvable');
})->with([
    '/.env', '/.git/config', '/.well-known/security.txt', '/admin/.hidden',
    '/composer.json', '/vendor/autoload.php', '/x.php', '/spa.html', '/index.php', '/robots.txt.bak',
    '/vendor', '/vendor/', '/storage/', '/storage/logs/laravel.log', '/config', '/database/', '/VENDOR/',
]);

it('répond 404 JSON « not_found » aux routes API inconnues, quel que soit le verbe', function (string $method, string $uri): void {
    buildSpa($this->publicPath);

    $this->call($method, $uri)
        ->assertNotFound()
        ->assertExactJson(['message' => 'Ressource introuvable.', 'code' => 'not_found']);
})->with([
    ['GET', '/api'],
    ['GET', '/api/inconnu'],
    ['POST', '/api/public/inconnu'],
    ['DELETE', '/api/admin/visitors/abc/rien'],
    ['GET', '/sanctum/inconnu'],
]);

it('indique que le SPA n\'est pas construit hors production', function (): void {
    $this->get('/visite/1')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('SPA non construite');
});

it('répond 404 en production si le SPA n\'est pas construit', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->get('/visite/1')->assertNotFound();
});
