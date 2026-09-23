<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\PasswordConfirmation;
use App\Services\Auth\PasswordResets;
use App\Services\Auth\UserSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Compte de l'utilisateur connecté : profil et mot de passe.
 */
class AccountController extends Controller
{
    /**
     * PATCH /api/auth/profile — { name?, email?, current_password? } → 200 { user }.
     * Changement d'e-mail : `current_password` obligatoire et vérifié (422 sur ce champ, 429 après
     * 5 échecs en 15 min, compteur partagé avec les autres confirmations de mot de passe).
     */
    public function updateProfile(
        UpdateProfileRequest $request,
        PasswordConfirmation $confirmation,
        AuditLogger $audit,
        PasswordResets $resets,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $originalEmail = $user->email;

        $user->fill($request->safe()->only(['name', 'email']));
        $fields = array_keys($user->getDirty());

        if (in_array('email', $fields, true)) {
            $confirmation->confirm($user, $request->string('current_password')->toString(), 'current_password');
        }

        if ($fields !== []) {
            $user->save();

            if (in_array('email', $fields, true)) {
                // Un lien de réinitialisation envoyé à l'ancienne adresse ne doit plus servir.
                $resets->forget($originalEmail);
            }

            $audit->log('user.updated', $user, ['fields' => $fields]);
        }

        return new JsonResponse(['user' => UserResource::make($user)->resolve($request)]);
    }

    /**
     * PUT /api/auth/password → 204. Toutes les AUTRES sessions du compte sont supprimées,
     * la session courante est régénérée.
     */
    public function updatePassword(
        UpdatePasswordRequest $request,
        PasswordConfirmation $confirmation,
        UserSessions $sessions,
        PasswordResets $resets,
        AuditLogger $audit,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        $confirmation->confirm($user, $request->string('current_password')->toString(), 'current_password');

        // Mot de passe en clair : le cast `hashed` le hache une seule fois (argon2id).
        $user->forceFill(['password' => $request->string('password')->toString()]);
        $user->setRememberToken(Str::random(60));
        $user->save();

        if ($request->hasSession()) {
            $request->session()->regenerate(true);
            $sessions->destroyOthers($user, $request->session()->getId());
        } else {
            $sessions->destroyAll($user);
        }

        $resets->forget($user->email);
        $audit->log('auth.password_changed', null, ['via' => 'profile'], $user);

        return response()->noContent();
    }
}
