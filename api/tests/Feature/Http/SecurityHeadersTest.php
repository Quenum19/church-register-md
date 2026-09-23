<?php

use App\Http\Middleware\SecurityHeaders;

$expected = [
    'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'",
    'X-Frame-Options' => 'DENY',
    'X-Content-Type-Options' => 'nosniff',
    'Referrer-Policy' => 'strict-origin-when-cross-origin',
    'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
];

it('ajoute les en-têtes de sécurité du contrat', function (string $method, string $uri) use ($expected): void {
    $response = $this->json($method, $uri);

    foreach ($expected as $header => $value) {
        $response->assertHeader($header, $value);
    }

    $response->assertHeaderMissing('Strict-Transport-Security');
})->with([
    'API' => ['GET', '/api/health'],
    'SPA' => ['GET', '/admin/visiteurs'],
    'erreur 404 API' => ['GET', '/api/inconnu'],
    'erreur 405 API' => ['DELETE', '/api/health'],
]);

it('n\'envoie HSTS qu\'en production et en HTTPS', function (): void {
    $this->get('https://localhost/api/health')->assertHeaderMissing('Strict-Transport-Security');

    app()->detectEnvironment(fn (): string => 'production');

    $this->get('http://localhost/api/health')->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://localhost/api/health')
        ->assertHeader('Strict-Transport-Security', SecurityHeaders::STRICT_TRANSPORT_SECURITY);
});

it('réaffirme dans public/.htaccess la CSP exacte de l\'application', function (): void {
    // Hostinger (LiteSpeed) impose au niveau serveur « Content-Security-Policy:
    // upgrade-insecure-requests », qui écrase l'en-tête posé par PHP. Le .htaccess, fusionné en
    // dernier, rétablit la nôtre : les deux définitions doivent donc rester identiques.
    $htaccess = file_get_contents(public_path('.htaccess'));

    expect($htaccess)->toBeString();

    $found = preg_match(
        '/^\s*Header always set Content-Security-Policy "(?P<policy>[^"]+)"/m',
        (string) $htaccess,
        $matches,
    );

    expect($found)->toBe(1, 'Aucune directive « Header always set Content-Security-Policy » dans public/.htaccess.');
    expect($matches['policy'])->toBe(SecurityHeaders::CONTENT_SECURITY_POLICY);
});
