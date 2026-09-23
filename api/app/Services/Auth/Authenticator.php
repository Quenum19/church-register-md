<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Connexion admin (contrat d'API §3) : vérification des identifiants en temps constant,
 * limitation des échecs, étape 2FA en attente, ouverture de la session.
 */
class Authenticator
{
    public const FAILED_MESSAGE = 'Identifiants incorrects.';

    /** Clé de session de la connexion en attente du code 2FA. */
    public const PENDING_SESSION_KEY = 'auth.two_factor_pending';

    public const PENDING_TTL_MINUTES = 5;

    public const PENDING_EXPIRED_MESSAGE = 'La vérification a expiré. Merci de saisir à nouveau vos identifiants.';

    /** Code d'erreur (422) d'un challenge 2FA sans connexion en attente valide (expirée, absente, abandonnée). */
    public const PENDING_EXPIRED_CODE = 'two_factor_expired';

    public const INVALID_CODE_MESSAGE = 'Le code est invalide.';

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Hasher $hasher,
        private readonly CacheRepository $cache,
        private readonly Config $config,
        private readonly LoginThrottle $throttle,
        private readonly TwoFactorAuthenticator $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Vérifie les identifiants et renvoie le compte (sans ouvrir de session).
     *
     * Temps constant : un compte inconnu, invité (sans mot de passe) ou désactivé est vérifié contre
     * un hachage argon2id factice et reçoit exactement le même message ; tout échec dure au moins
     * `auth.timebox_duration` µs.
     *
     * Pas d'énumération : un mot de passe faux donne TOUJOURS la 422 générique, que le compte
     * soit inconnu, désactivé ou verrouillé. Le 423 n'apparaît qu'avec les bons identifiants —
     * une adresse inconnue et une adresse verrouillée sont donc indiscernables pour un attaquant.
     *
     * @throws ApiException 429 (trop d'échecs pour e-mail + IP) ou 423 (compte verrouillé)
     * @throws ValidationException 422 « Identifiants incorrects. » sur `email`
     */
    public function attempt(string $email, #[\SensitiveParameter] string $password, string $ip): User
    {
        $this->throttle->ensureNotRateLimited($email, $ip);

        return (new Timebox)->call(function (Timebox $timebox) use ($email, $password, $ip): User {
            $user = User::query()->where('email', Str::lower(trim($email)))->first();
            $account = $user !== null && $user->is_active && $user->password !== null ? $user : null;
            $valid = $this->hasher->check($password, $account->password ?? $this->dummyHash());

            if ($account === null || ! $valid) {
                $this->throttle->recordFailure($email, $ip, $account);
                $this->audit->log('auth.login_failed', null, ['stage' => 'password'], $account, LoginThrottle::maskIp($ip));

                throw ValidationException::withMessages(['email' => self::FAILED_MESSAGE]);
            }

            // Mot de passe correct : c'est le SEUL cas où le verrouillage est révélé (423).
            $this->throttle->ensureNotLocked($account, $ip);

            if ($this->hasher->needsRehash((string) $account->password)) {
                $account->forceFill(['password' => $password])->save();
            }

            $timebox->returnEarly();

            return $account;
        }, $this->timeboxMicroseconds());
    }

    /**
     * Mot de passe correct et 2FA confirmée : aucune session ouverte, la connexion est mise
     * en attente (5 min) dans une session régénérée.
     */
    public function startTwoFactorChallenge(Request $request, User $user): void
    {
        $session = $request->session();
        $session->regenerate();
        $session->put(self::PENDING_SESSION_KEY, [
            'user_id' => $user->getKey(),
            'expires_at' => Carbon::now()->addMinutes(self::PENDING_TTL_MINUTES)->getTimestamp(),
        ]);
    }

    /**
     * Étape 2FA : code TOTP ou code de récupération.
     *
     * @throws ApiException 422 `two_factor_expired` (aucune connexion en attente valide : le SPA
     *                      revient à la saisie des identifiants), 429 / 423
     * @throws ValidationException 422 (code invalide)
     */
    public function completeTwoFactorChallenge(Request $request, ?string $code, ?string $recoveryCode): User
    {
        $field = $code !== null ? 'code' : 'recovery_code';
        $user = $this->pendingUser($request);

        if ($user === null) {
            throw new ApiException(
                self::PENDING_EXPIRED_MESSAGE,
                self::PENDING_EXPIRED_CODE,
                422,
                [$field => [self::PENDING_EXPIRED_MESSAGE]],
            );
        }

        $ip = (string) $request->ip();
        $this->throttle->ensureNotRateLimited($user->email, $ip);
        $locked = $this->throttle->lockSeconds($user, $ip);

        if ($locked !== null) {
            $this->forgetPending($request);

            throw LoginThrottle::lockedException($locked);
        }

        $valid = $code !== null
            ? $this->twoFactor->verifyCode($user, $code)
            : $this->twoFactor->consumeRecoveryCode($user, (string) $recoveryCode);

        if (! $valid) {
            $justLocked = $this->throttle->recordFailure($user->email, $ip, $user);
            $this->audit->log('auth.login_failed', null, ['stage' => 'two_factor'], $user, LoginThrottle::maskIp($ip));

            // Ici le mot de passe a déjà été prouvé : révéler le verrouillage n'apprend rien.
            if ($justLocked) {
                $this->forgetPending($request);

                throw LoginThrottle::lockedException(
                    $this->throttle->lockSeconds($user, $ip) ?? LoginThrottle::LOCK_MINUTES * 60,
                );
            }

            throw ValidationException::withMessages([$field => self::INVALID_CODE_MESSAGE]);
        }

        $meta = ['two_factor' => $code !== null ? 'totp' : 'recovery_code'];

        if ($code === null) {
            $meta['recovery_codes_left'] = count($user->two_factor_recovery_codes ?? []);
        }

        $this->login($request, $user, $meta);

        return $user;
    }

    /**
     * Ouvre la session : connexion, régénération de l'identifiant de session (et du jeton CSRF),
     * remise à zéro des compteurs, `last_login_at`, journal `auth.login`.
     *
     * @param  array<string, mixed>  $meta
     */
    public function login(Request $request, User $user, array $meta = []): void
    {
        $this->forgetPending($request);
        $this->guard()->login($user);
        $request->session()->regenerate();

        $this->throttle->clear($user->email, (string) $request->ip(), $user);
        $user->forceFill(['last_login_at' => Carbon::now(), 'failed_attempts' => 0, 'locked_until' => null])->save();

        $this->audit->log('auth.login', null, $meta, $user);
    }

    /**
     * Déconnexion réelle : session invalidée côté serveur, nouveau jeton CSRF.
     */
    public function logout(Request $request): void
    {
        $this->guard()->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    /**
     * Compte dont la connexion attend le code 2FA, ou null si aucune attente valide.
     */
    public function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(self::PENDING_SESSION_KEY);

        if (! is_array($pending)
            || ! is_int($pending['user_id'] ?? null)
            || ! is_int($pending['expires_at'] ?? null)
            || $pending['expires_at'] < Carbon::now()->getTimestamp()) {
            $this->forgetPending($request);

            return null;
        }

        $user = User::query()->find($pending['user_id']);

        if ($user === null || ! $user->is_active || $user->password === null || ! $user->hasTwoFactorEnabled()) {
            $this->forgetPending($request);

            return null;
        }

        return $user;
    }

    private function forgetPending(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::PENDING_SESSION_KEY);
        }
    }

    private function guard(): StatefulGuard
    {
        $guard = $this->auth->guard('web');

        if (! $guard instanceof StatefulGuard) {
            throw new LogicException('La garde « web » doit être une garde de session.');
        }

        return $guard;
    }

    /**
     * Hachage argon2id d'une valeur aléatoire, calculé avec les paramètres courants (même coût
     * de vérification qu'un vrai mot de passe) et mis en cache.
     */
    private function dummyHash(): string
    {
        $key = 'auth.dummy_hash.'.hash('xxh128', (string) json_encode([
            $this->config->get('hashing.driver'),
            $this->config->get('hashing.argon'),
            $this->config->get('hashing.bcrypt.rounds'),
        ]));

        return $this->cache->rememberForever($key, fn (): string => $this->hasher->make(Str::random(40)));
    }

    private function timeboxMicroseconds(): int
    {
        $value = $this->config->get('auth.timebox_duration', 200000);

        return is_numeric($value) ? max(0, (int) $value) : 200000;
    }
}
