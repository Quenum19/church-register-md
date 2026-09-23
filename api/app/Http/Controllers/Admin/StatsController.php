<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Queries\DashboardStatsQuery;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/admin/stats (visitors.view) — sans enveloppe `data`, cache 5 min.
 */
class StatsController extends Controller
{
    public function __invoke(DashboardStatsQuery $stats): JsonResponse
    {
        return new JsonResponse($stats->get());
    }
}
