<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmPasswordRequest;
use App\Http\Requests\Auth\ConfirmTwoFactorRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\PasswordConfirmation;
use App\Services\Auth\TwoFactorAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Activation, confirmation et désactivation de la double authentification (TOTP).
 */
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorAuthenticator $twoFactor,
        private readonly PasswordConfirmation $confirmation,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /api/auth/two-factor/enable — { password } → 200 { secret, otpauth_url, recovery_codes }.
     * La 2FA reste inactive tant qu'elle n'est pas confirmée par un code.
     */
    public function enable(ConfirmPasswordRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $this->confirmation->confirm($user, $request->string('password')->toString());

        if ($user->hasTwoFactorEnabled()) {
            throw new ApiException(
                'La double authentification est déjà activée. Désactivez-la avant de la configurer à nouveau.',
                'two_factor_already_enabled',
                409,
            );
        }

        return new JsonResponse($this->twoFactor->prepare($user));
    }

    /**
     * POST /api/auth/two-factor/confirm — { code } → 204.
     */
    public function confirm(ConfirmTwoFactorRequest $request): Response
    {
        $user = $this->user($request);

        if ($user->two_factor_secret === null) {
            throw ValidationException::withMessages([
                'code' => "Aucune configuration de double authentification n'est en cours. Recommencez l'activation.",
            ]);
        }

        if (! $this->twoFactor->verifyCode($user, $request->string('code')->toString())) {
            throw ValidationException::withMessages(['code' => 'Le code est invalide.']);
        }

        if ($user->two_factor_confirmed_at === null) {
            $user->forceFill(['two_factor_confirmed_at' => Carbon::now()])->save();
            $this->audit->log('auth.two_factor_enabled', null, [], $user);
        }

        return response()->noContent();
    }

    /**
     * DELETE /api/auth/two-factor — { password } → 204.
     */
    public function disable(ConfirmPasswordRequest $request): Response
    {
        $user = $this->user($request);
        $this->confirmation->confirm($user, $request->string('password')->toString());

        $wasEnabled = $user->hasTwoFactorEnabled();
        $this->twoFactor->disable($user);

        if ($wasEnabled) {
            $this->audit->log('auth.two_factor_disabled', null, [], $user);
        }

        return response()->noContent();
    }

    private function user(ConfirmPasswordRequest|ConfirmTwoFactorRequest $request): User
    {
        /** @var User */
        return $request->user();
    }
}
