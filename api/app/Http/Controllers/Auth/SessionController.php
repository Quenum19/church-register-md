<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\Authenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Session courante : GET /api/auth/me et POST /api/auth/logout.
 */
class SessionController extends Controller
{
    /**
     * GET /api/auth/me → { user, abilities }.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return new JsonResponse([
            'user' => UserResource::make($user)->resolve($request),
            'abilities' => $user->abilities(),
        ]);
    }

    /**
     * POST /api/auth/logout → 204 (session invalidée côté serveur, nouveau jeton CSRF).
     */
    public function destroy(Request $request, Authenticator $authenticator, AuditLogger $audit): Response
    {
        /** @var User $user */
        $user = $request->user();

        $audit->log('auth.logout', null, [], $user);
        $authenticator->logout($request);

        return response()->noContent();
    }
}
