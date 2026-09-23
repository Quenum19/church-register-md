<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\Auth\PasswordResets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Mot de passe oublié et définition du mot de passe (réinitialisation ou invitation).
 */
class PasswordResetController extends Controller
{
    public const NEUTRAL_MESSAGE = "Si un compte actif correspond à cette adresse, un e-mail contenant un lien de réinitialisation vient d'être envoyé.";

    public const INVALID_LINK_MESSAGE = "Ce lien n'est plus valide : il a expiré ou a déjà été utilisé. Demandez un nouveau lien.";

    /**
     * POST /api/auth/forgot-password → 200 toujours (aucune énumération des comptes).
     */
    public function sendLink(ForgotPasswordRequest $request, PasswordResets $resets): JsonResponse
    {
        $resets->sendResetLink($request->normalizedEmail());

        return new JsonResponse(['message' => self::NEUTRAL_MESSAGE]);
    }

    /**
     * POST /api/auth/reset-password → 204 ; lien invalide ou expiré → 422 sur `token`
     * (même réponse que l'adresse soit connue ou non).
     */
    public function reset(ResetPasswordRequest $request, PasswordResets $resets): Response
    {
        $via = $resets->reset(
            $request->normalizedEmail(),
            $request->string('token')->toString(),
            $request->string('password')->toString(),
        );

        if ($via === null) {
            throw ValidationException::withMessages(['token' => self::INVALID_LINK_MESSAGE]);
        }

        return response()->noContent();
    }
}
