<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Queries\DashboardStatsQuery;
use Illuminate\Support\Facades\Cache;

/**
 * Invalidation des caches dérivés après une écriture admin.
 */
trait InvalidatesCaches
{
    /** Clé du cache de GET /api/public/config (API publique, 60 s). */
    public const PUBLIC_CONFIG_CACHE_KEY = 'public:config';

    /**
     * Configuration publique : nom, URL, verset, famille du mois (paramètres, rotations).
     */
    protected function forgetPublicConfig(): void
    {
        Cache::forget(self::PUBLIC_CONFIG_CACHE_KEY);
    }

    /**
     * Statistiques du tableau de bord (statuts, familles, volumes).
     */
    protected function forgetStats(): void
    {
        app(DashboardStatsQuery::class)->forget();
    }
}
