<?php

namespace App\Support;

/**
 * Préfixes et piles de middlewares des fichiers de routes API (enregistrés dans bootstrap/app.php).
 * Réutilisables dans les tests pour déclarer une route factice dans un groupe réel.
 */
final class RouteGroups
{
    /** routes/api/public.php — sans session, sans cookie, sans CSRF ; 300 requêtes/min par IP. */
    public const PUBLIC_PREFIX = 'api/public';

    public const PUBLIC_MIDDLEWARE = ['public'];

    /** routes/api/auth.php — Sanctum SPA (session + CSRF pour SANCTUM_STATEFUL_DOMAINS). */
    public const AUTH_PREFIX = 'api/auth';

    public const AUTH_MIDDLEWARE = ['api'];

    /** routes/api/admin.php et routes/api/reports.php — authentifié, compte actif, 120/min. */
    public const ADMIN_PREFIX = 'api/admin';

    public const ADMIN_MIDDLEWARE = ['api', 'auth:sanctum', 'active', 'throttle:'.RateLimits::ADMIN];
}
