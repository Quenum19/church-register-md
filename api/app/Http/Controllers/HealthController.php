<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GET /api/health : 200 { status: "ok", db: "ok" } ou 503 si la base est injoignable.
 * Utilisé par le test de fumée du déploiement ; hors limites de débit.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::connection()->select('select 1');
        } catch (Throwable $e) {
            Log::error('Sonde /api/health : base de données injoignable.', ['exception' => $e::class]);

            return $this->noCache(new JsonResponse(['status' => 'error', 'db' => 'error'], 503));
        }

        return $this->noCache(new JsonResponse(['status' => 'ok', 'db' => 'ok']));
    }

    private function noCache(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
