<?php

use App\Exceptions\ApiException;
use App\Exceptions\ApiExceptionRenderer;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HideRateLimitHeaders;
use App\Http\Middleware\LimitPublicPayload;
use App\Http\Middleware\SecurityHeaders;
use App\Support\RateLimits;
use App\Support\RouteGroups;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        using: function (): void {
            // Sonde de disponibilité (déploiement, surveillance) : sans session, et limitée à
            // 60 requêtes/min par IP (elle interroge la base : sans limite, elle est un levier
            // d'épuisement des connexions pour un appelant anonyme).
            Route::get('api/health', HealthController::class)
                ->middleware('throttle:'.RateLimits::HEALTH_PER_MINUTE.',1')
                ->name('health');

            // API publique (parcours visiteur) — SANS session, cookie ni CSRF. Agent « API publique ».
            Route::prefix(RouteGroups::PUBLIC_PREFIX)
                ->middleware(RouteGroups::PUBLIC_MIDDLEWARE)
                ->name('public.')
                ->group(base_path('routes/api/public.php'));

            // Authentification admin — Sanctum SPA (session + CSRF). Agent « API admin ».
            Route::prefix(RouteGroups::AUTH_PREFIX)
                ->middleware(RouteGroups::AUTH_MIDDLEWARE)
                ->name('auth.')
                ->group(base_path('routes/api/auth.php'));

            // API admin, découpée par propriétaire : admin.php (visiteurs, stats, exports, rotations,
            // paramètres), users.php (administrateurs, journal d'audit), reports.php (rapports, destinataires).
            // Même préfixe /api/admin, authentifié, compte actif, 120 requêtes/min par utilisateur.
            foreach (['admin', 'users', 'reports'] as $file) {
                Route::prefix(RouteGroups::ADMIN_PREFIX)
                    ->middleware(RouteGroups::ADMIN_MIDDLEWARE)
                    ->name('admin.')
                    ->group(base_path("routes/api/{$file}.php"));
            }

            // Repli SPA : volontairement hors du groupe « web » (aucune session sur les pages du SPA).
            Route::group([], base_path('routes/web.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Proxies de confiance : config/trustedproxy.php (variable TRUSTED_PROXIES), lu par TrustProxies.

        // Tout premiers de la pile globale, dans cet ordre :
        // 1. SecurityHeaders, pour couvrir VRAIMENT toutes les réponses (API, SPA, erreurs,
        //    y compris celles produites par les middlewares suivants) ;
        // 2. LimitPublicPayload, qui doit s'exécuter AVANT TrimStrings : ce dernier parcourt
        //    l'intégralité du corps (mesuré : ~1,3 s pour 2,5 Mo, ~25 s pour 10 Mo), ce qui
        //    ferait d'une simple requête anonyme un levier d'épuisement CPU.
        $middleware->prepend([SecurityHeaders::class, LimitPublicPayload::class]);

        // Sanctum SPA : session + CSRF ajoutés au groupe « api » pour les requêtes venant du SPA
        // (SANCTUM_STATEFUL_DOMAINS). Le groupe « public » n'en bénéficie jamais.
        $middleware->statefulApi();

        // HideRateLimitHeaders est déclaré en premier : la post-exécution se faisant en ordre
        // inverse, il nettoie les en-têtes X-RateLimit-* posés par les limiteurs.
        // (La borne du corps public est appliquée plus haut, dans la pile globale.)
        $middleware->group('public', [
            HideRateLimitHeaders::class,
            'throttle:'.RateLimits::PUBLIC,
            SubstituteBindings::class,
        ]);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
        ]);

        // API uniquement : un invité reçoit 401 JSON, jamais de redirection vers une page de connexion.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([ApiException::class]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api', 'api/*', 'sanctum/*') || $request->expectsJson(),
        );

        // Format du contrat §0 : { message, code, errors? } ; 500 toujours générique.
        $exceptions->render(new ApiExceptionRenderer);
    })->create();
