<?php

use App\Support\RateLimits;

it('répond ok quand la base est joignable', function (): void {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'db' => 'ok'])
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeaderMissing('Set-Cookie');
});

it('répond 503 quand la base est injoignable', function (): void {
    // Connexion par défaut vers un port fermé (la connexion des tests reste intacte).
    $default = config('database.default');
    config([
        'database.connections.injoignable' => [...config("database.connections.{$default}"), 'port' => 1],
        'database.default' => 'injoignable',
    ]);

    try {
        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'error', 'db' => 'error']);
    } finally {
        config(['database.default' => $default]);
    }
});

it('est limitée à 60 requêtes par minute et par IP', function (): void {
    // La sonde interroge la base : sans limite, elle sert d'épuisement des connexions à un
    // appelant anonyme. La surveillance (une requête par minute) passe très largement.
    $this->getJson('/api/health')
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', (string) RateLimits::HEALTH_PER_MINUTE)
        ->assertHeader('X-RateLimit-Remaining', (string) (RateLimits::HEALTH_PER_MINUTE - 1));

    foreach (range(2, RateLimits::HEALTH_PER_MINUTE) as $ignored) {
        $this->getJson('/api/health')->assertOk();
    }

    $this->getJson('/api/health')
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'too_many_requests')
        ->assertHeader('Retry-After');
});
