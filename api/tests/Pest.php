<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Configuration Pest
|--------------------------------------------------------------------------
|
| Tests « Feature » : application complète + base MariaDB réinitialisée (RefreshDatabase,
| chaque test dans une transaction annulée). Tests « Unit » : TestCase Laravel sans base.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Fonctions utilitaires
|--------------------------------------------------------------------------
*/

/**
 * Crée un utilisateur actif du rôle donné, relu en base : toutes ses colonnes sont chargées,
 * comme pour le compte que la garde de session relit à chaque requête (sinon
 * preventAccessingMissingAttributes lève une exception, par exemple sur GET /api/auth/me).
 *
 * @param  array<string, mixed>  $attributes
 */
function userWithRole(Role $role, array $attributes = []): User
{
    return User::factory()->role($role)->create($attributes)->fresh()
        ?? throw new LogicException('Utilisateur de test introuvable après sa création.');
}

/**
 * actingAs() avec le compte relu en base (même raison que userWithRole()) : à utiliser avec un
 * compte créé directement par la factory.
 */
function authActingAs(User $user): TestCase
{
    return test()->actingAs($user->fresh() ?? $user);
}

/**
 * Déclare une route factice dans un groupe réel (préfixe + middlewares de App\Support\RouteGroups),
 * pour tester le comportement d'un groupe indépendamment des routes métier.
 *
 * @param  list<string>  $middleware
 */
function testRoute(string $prefix, array $middleware, string $method, string $uri, Closure $action): void
{
    Route::prefix($prefix)->middleware($middleware)->group(function () use ($method, $uri, $action): void {
        Route::match([$method], $uri, $action);
    });
}
