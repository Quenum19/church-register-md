<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\RequiresSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\Authenticator;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/auth/two-factor/challenge — { code } ou { recovery_code } → 200 { user } ; 422 (code faux) ;
 * 422 `two_factor_expired` (connexion en attente expirée ou absente) ; 423 ; 429.
 */
class TwoFactorChallengeController extends Controller
{
    use RequiresSession;

    public function __invoke(TwoFactorChallengeRequest $request, Authenticator $authenticator): JsonResponse
    {
        $this->ensureSession($request);

        $user = $authenticator->completeTwoFactorChallenge($request, $request->totpCode(), $request->recoveryCode());

        return new JsonResponse(['user' => UserResource::make($user)->resolve($request)]);
    }
}
