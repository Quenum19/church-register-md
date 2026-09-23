<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreVisitRequest;
use App\Services\Journey\JourneyErrors;
use App\Services\Journey\RecordedVisit;
use App\Services\Journey\VisitAnswersValidator;
use App\Services\Journey\VisitRecorder;
use App\Services\Journey\VisitTokenService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/public/visits (contrat §2), dans l'ordre imposé :
 * 1. validation de forme (StoreVisitRequest) ;
 * 2. rejeu idempotent (200), même si le jeton a expiré ou a déjà servi ;
 * 3. jeton : 401 token_invalid, 410 token_expired, 409 token_used ;
 * 4. validation des réponses selon l'étape du jeton (422) ;
 * 5. transaction : 409 step_mismatch / already_today, insertion, statut, jeton consommé ;
 * 6. 201 { visit_number, family, completed }.
 */
class StoreVisitController extends Controller
{
    public function __invoke(
        StoreVisitRequest $request,
        VisitTokenService $tokens,
        VisitAnswersValidator $answers,
        VisitRecorder $recorder,
    ): JsonResponse {
        $idempotencyKey = $request->idempotencyKey();
        $sessionToken = $request->sessionToken();

        $replayed = $recorder->replay($idempotencyKey, $sessionToken);

        if ($replayed !== null) {
            return $this->respond($replayed);
        }

        $token = $tokens->decode($sessionToken);

        if ($tokens->isConsumed($token)) {
            throw JourneyErrors::tokenUsed();
        }

        $submission = $answers->validate($token, $request->answers(), $request->consent());

        return $this->respond($recorder->record($token, $idempotencyKey, $submission));
    }

    private function respond(RecordedVisit $visit): JsonResponse
    {
        return new JsonResponse($visit->toArray(), $visit->status());
    }
}
