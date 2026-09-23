<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\Journey\PublicConfigService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/public/config : nom de l'Église, URL publique, verset, famille du mois et familles
 * (contrat §2). Aucune donnée personnelle.
 */
class ConfigController extends Controller
{
    public function __invoke(PublicConfigService $config): JsonResponse
    {
        return new JsonResponse($config->get());
    }
}
