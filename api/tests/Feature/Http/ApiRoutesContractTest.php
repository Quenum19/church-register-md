<?php

use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;

/*
| Inventaire des routes exposées, comparé au contrat d'API (§2 à §5) : chaque route du contrat
| existe avec sa méthode, son chemin, son authentification et son ability, et aucune autre route
| n'est exposée (pas de /up, ni de /storage/* de Laravel).
*/

/**
 * « MÉTHODE /chemin [auth] [ability] » de chaque route enregistrée, triées.
 *
 * @return list<string>
 */
function contractExposedRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->map(function (IlluminateRoute $route): string {
            $methods = implode('|', array_diff($route->methods(), ['HEAD']));
            $middleware = array_filter($route->gatherMiddleware(), 'is_string');
            $ability = collect($middleware)->first(fn (string $name): bool => str_starts_with($name, 'can:'));

            return implode(' ', array_filter([
                $methods,
                '/'.ltrim($route->uri(), '/'),
                in_array('auth:sanctum', $middleware, true) ? 'auth' : null,
                $ability !== null ? substr($ability, 4) : null,
            ]));
        })
        ->sort()
        ->values()
        ->all();
}

it('expose exactement les routes du contrat, avec leur authentification et leur ability', function (): void {
    $expected = [
        // §2 API publique (sans session).
        'GET /api/public/config',
        'POST /api/public/identify',
        'POST /api/public/visits',

        // §3 Authentification admin.
        'GET /sanctum/csrf-cookie',
        'POST /api/auth/login',
        'POST /api/auth/two-factor/challenge',
        'POST /api/auth/logout auth',
        'GET /api/auth/me auth',
        'PATCH /api/auth/profile auth',
        'PUT /api/auth/password auth',
        'POST /api/auth/forgot-password',
        'POST /api/auth/reset-password',
        'POST /api/auth/two-factor/enable auth',
        'POST /api/auth/two-factor/confirm auth',
        'DELETE /api/auth/two-factor auth',

        // §4 API admin.
        'GET /api/admin/stats auth visitors.view',
        'GET /api/admin/visitors auth visitors.view',
        'GET /api/admin/visitors/{visitor} auth visitors.view',
        'PATCH /api/admin/visitors/{visitor} auth visitors.update',
        'DELETE /api/admin/visitors/{visitor} auth visitors.delete',
        'POST /api/admin/visitors/{visitor}/convert auth visitors.convert',
        'DELETE /api/admin/visitors/{visitor}/convert auth visitors.unconvert',
        'POST /api/admin/visitors/{visitor}/notes auth notes.create',
        'DELETE /api/admin/notes/{note} auth delete,note',
        'GET /api/admin/members auth visitors.view',
        'GET /api/admin/exports/visitors.csv auth visitors.export',
        'GET /api/admin/exports/visitors.xlsx auth visitors.export',
        'GET /api/admin/exports/visitors.pdf auth visitors.export',
        'GET /api/admin/reports auth visitors.view',
        'GET /api/admin/reports/{year}/{month} auth visitors.view',
        'POST /api/admin/reports/{year}/{month}/send auth reports.send',
        'GET /api/admin/report-recipients auth recipients.manage',
        'POST /api/admin/report-recipients auth recipients.manage',
        'PATCH /api/admin/report-recipients/{recipient} auth recipients.manage',
        'DELETE /api/admin/report-recipients/{recipient} auth recipients.manage',
        'POST /api/admin/report-recipients/test auth recipients.manage',
        'GET /api/admin/families auth visitors.view',
        'GET /api/admin/rotations auth visitors.view',
        'PUT /api/admin/rotations/{year}/{month} auth rotations.manage',
        'GET /api/admin/settings auth visitors.view',
        'PUT /api/admin/settings auth settings.update',
        'GET /api/admin/users auth users.manage',
        'POST /api/admin/users auth users.manage',
        'PATCH /api/admin/users/{user} auth users.manage',
        'DELETE /api/admin/users/{user} auth users.manage',
        'POST /api/admin/users/{user}/invitation auth users.manage',
        'GET /api/admin/audit-logs auth audit.view',

        // §5 Divers : sonde de santé et repli du SPA (seule route hors API).
        'GET /api/health',
        'GET /{fallbackPlaceholder}',
    ];

    expect(contractExposedRoutes())->toBe(collect($expected)->sort()->values()->all());
});

it('protège chaque route admin par le compte actif et la limite « admin »', function (): void {
    $admin = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (IlluminateRoute $route): bool => str_starts_with($route->uri(), 'api/admin/'));

    expect($admin)->not->toBeEmpty();

    foreach ($admin as $route) {
        expect($route->gatherMiddleware())->toContain('auth:sanctum', 'active', 'throttle:admin');
    }
});
