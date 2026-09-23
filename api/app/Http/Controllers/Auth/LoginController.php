<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\RequiresSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\Authenticator;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/auth/login → 200 { user } | { two_factor_required: true } ; 422 ; 423 ; 429.
 */
class LoginController extends Controller
{
    use RequiresSession;

    public function __invoke(LoginRequest $request, Authenticator $authenticator): JsonResponse
    {
        $this->ensureSession($request);

        $user = $authenticator->attempt($request->loginEmail(), $request->loginPassword(), (string) $request->ip());

        if ($user->hasTwoFactorEnabled()) {
            $authenticator->startTwoFactorChallenge($request, $user);

            return new JsonResponse(['two_factor_required' => true]);
        }

        $authenticator->login($request, $user);

        return new JsonResponse(['user' => UserResource::make($user)->resolve($request)]);
    }
}
