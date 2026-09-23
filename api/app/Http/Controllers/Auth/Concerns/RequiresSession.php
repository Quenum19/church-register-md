<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Exceptions\ApiException;
use Illuminate\Http\Request;

/**
 * La connexion n'a de sens qu'avec une session Sanctum SPA (requête venant d'un domaine
 * SANCTUM_STATEFUL_DOMAINS). Un client sans session reçoit une erreur claire plutôt qu'une 500.
 */
trait RequiresSession
{
    protected function ensureSession(Request $request): void
    {
        if (! $request->hasSession()) {
            throw new ApiException(
                'La connexion doit être effectuée depuis le tableau de bord (session requise).',
                'bad_request',
                400,
            );
        }
    }
}
