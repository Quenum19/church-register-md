<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\IdentifyRequest;
use App\Services\Journey\IdentificationService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/public/identify (contrat §2) : { step, session_token, expires_in }, toujours
 * la même forme, sans aucune donnée personnelle.
 *
 * Deux limites : par IP (throttle:identify, 200/heure par défaut) et par numéro
 * (5 jetons/heure, appliquée par IdentificationService). Les en-têtes `X-RateLimit-*`
 * sont retirés des réponses (App\Http\Middleware\HideRateLimitHeaders).
 */
class IdentifyController extends Controller
{
    public function __invoke(IdentifyRequest $request, IdentificationService $identification): JsonResponse
    {
        return new JsonResponse($identification->identify($request->phoneE164(), $request->country()));
    }
}
