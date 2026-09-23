<?php

use App\Models\User;
use App\Support\RouteGroups;
use Illuminate\Support\Facades\Auth;

beforeEach(function (): void {
    testRoute(RouteGroups::ADMIN_PREFIX, RouteGroups::ADMIN_MIDDLEWARE, 'GET', '__me', fn () => ['id' => Auth::id()]);
});

it('laisse passer un compte actif', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/admin/__me')->assertOk()->assertExactJson(['id' => $user->id]);
});

it('déconnecte un compte désactivé et répond 401', function (): void {
    $user = User::factory()->inactive()->create();

    $this->actingAs($user)
        ->getJson('/api/admin/__me')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Votre compte est désactivé.', 'code' => 'unauthenticated']);

    $this->assertGuest('web');
});

it('détruit la session d\'un compte désactivé en cours de session', function (): void {
    $user = User::factory()->create();
    $spa = ['Referer' => 'http://localhost/admin'];

    $this->actingAs($user)->withHeaders($spa)->getJson('/api/admin/__me')->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->withHeaders($spa)->getJson('/api/admin/__me')->assertUnauthorized();
    $this->assertGuest('web');
});
