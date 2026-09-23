<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Notifications\Auth\AccountLockedNotification;
use App\Services\AuditLogger;
use App\Support\RateLimits;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Carbon;

/**
 * Limitation des échecs de connexion (contrat d'API §3) :
 * - 5 échecs / 15 min par couple (e-mail + IP) → 429 avec Retry-After ;
 * - 20 échecs / heure sur un compte DEPUIS UNE MÊME IP → cette IP est bloquée 15 min pour ce
 *   compte ; le titulaire continue de se connecter normalement depuis ailleurs ;
 * - 100 échecs / heure sur un compte, toutes IP confondues → verrouillage GLOBAL 15 min
 *   (`users.locked_until`), pour le seul cas d'une attaque réellement distribuée.
 *
 * Pourquoi deux seuils : un seuil unique « par compte, toutes IP » transforme le verrouillage
 * en déni de service ciblé — avec 4 adresses IP, un tiers exclut durablement un administrateur.
 * Le blocage par (compte + IP) arrête l'attaquant sans jamais atteindre le titulaire ; le seuil
 * global, cinq fois plus haut, reste la dernière barrière.
 *
 * Le verrouillage n'est jamais révélé à qui ne connaît pas le mot de passe : la 423 n'est
 * renvoyée qu'avec des identifiants corrects (voir Authenticator). L'e-mail de notification est
 * limité à un par heure et par compte, sinon il devient lui-même une arme (flood de la boîte).
 *
 * Seuls les ÉCHECS sont comptés (compteurs manuels, pas le middleware throttle:login).
 * La 2FA (challenge) partage exactement les mêmes compteurs que le mot de passe.
 */
class LoginThrottle
{
    /** Échecs par heure sur un compte depuis une MÊME IP → blocage de cette IP. */
    public const ACCOUNT_IP_MAX_FAILURES = 20;

    /** Échecs par heure sur un compte, toutes IP confondues → verrouillage global. */
    public const ACCOUNT_MAX_FAILURES = 100;

    public const ACCOUNT_WINDOW_SECONDS = 3600;

    public const LOCK_MINUTES = 15;

    /** Au plus un e-mail de verrouillage par heure et par compte. */
    public const NOTICE_WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ApiException 429 si le couple (e-mail + IP) a atteint la limite d'échecs
     */
    public function ensureNotRateLimited(string $email, string $ip): void
    {
        $key = RateLimits::loginKey($email, $ip);

        if (! $this->limiter->tooManyAttempts($key, RateLimits::LOGIN_MAX_ATTEMPTS)) {
            return;
        }

        $seconds = max(1, $this->limiter->availableIn($key));

        throw new ApiException(
            'Trop de tentatives de connexion. Réessayez dans '.self::humanDelay($seconds).'.',
            'too_many_requests',
            429,
            headers: ['Retry-After' => (string) $seconds],
        );
    }

    /**
     * @throws ApiException 423 si le compte est verrouillé, globalement ou pour cette IP
     */
    public function ensureNotLocked(User $user, string $ip): void
    {
        $seconds = $this->lockSeconds($user, $ip);

        if ($seconds !== null) {
            throw self::lockedException($seconds);
        }
    }

    /**
     * Secondes de verrouillage restantes pour ce compte vu de cette IP, ou null s'il est ouvert.
     */
    public function lockSeconds(User $user, string $ip): ?int
    {
        $key = self::ipLockKey($user, $ip);

        $seconds = max(
            $user->isLocked() && $user->locked_until !== null
                ? max(1, (int) ceil(Carbon::now()->diffInSeconds($user->locked_until)))
                : 0,
            $this->limiter->tooManyAttempts($key, 1) ? max(1, $this->limiter->availableIn($key)) : 0,
        );

        return $seconds > 0 ? $seconds : null;
    }

    /**
     * Enregistre un échec de connexion (mot de passe ou code 2FA).
     *
     * @param  User|null  $account  compte éligible visé (actif, mot de passe défini), sinon null :
     *                              un compte inconnu, invité ou désactivé ne compte que pour (e-mail + IP).
     * @return bool true si cet échec vient de verrouiller le compte (globalement ou pour cette IP)
     */
    public function recordFailure(string $email, string $ip, ?User $account): bool
    {
        $this->limiter->hit(RateLimits::loginKey($email, $ip), RateLimits::LOGIN_DECAY_MINUTES * 60);

        // Pendant un verrouillage, les échecs ne relancent ni verrouillage ni notification.
        // Une autre IP, elle, continue d'alimenter le compteur global.
        if ($account === null || $account->isLocked() || $this->isIpLocked($account, $ip)) {
            return false;
        }

        $perIp = $this->limiter->hit(self::accountIpKey($account, $ip), self::ACCOUNT_WINDOW_SECONDS);
        $overall = $this->limiter->hit(self::accountKey($account), self::ACCOUNT_WINDOW_SECONDS);
        $account->forceFill(['failed_attempts' => min($account->failed_attempts + 1, 65535)]);

        $lockIp = $perIp >= self::ACCOUNT_IP_MAX_FAILURES;
        $lockAccount = $overall >= self::ACCOUNT_MAX_FAILURES;

        if (! $lockIp && ! $lockAccount) {
            $account->save();

            return false;
        }

        $this->lock($account, $ip, $lockIp, $lockAccount);

        return true;
    }

    /**
     * Connexion réussie : remise à zéro des compteurs de ce couple (e-mail + IP) et du compte.
     */
    public function clear(string $email, string $ip, User $account): void
    {
        $this->limiter->clear(RateLimits::loginKey($email, $ip));
        $this->limiter->clear(self::accountIpKey($account, $ip));
        $this->limiter->clear(self::ipLockKey($account, $ip));
        $this->clearAccount($account, $ip);
    }

    /**
     * Remise à zéro du compteur global par compte (ex. mot de passe réinitialisé), et du blocage
     * de l'IP courante s'il est connu : après un changement de mot de passe, la personne doit
     * pouvoir se reconnecter immédiatement depuis la machine d'où elle vient de le faire.
     */
    public function clearAccount(User $account, ?string $ip = null): void
    {
        $this->limiter->clear(self::accountKey($account));

        if ($ip !== null && $ip !== '') {
            $this->limiter->clear(self::accountIpKey($account, $ip));
            $this->limiter->clear(self::ipLockKey($account, $ip));
        }
    }

    public function isIpLocked(User $user, string $ip): bool
    {
        return $this->limiter->tooManyAttempts(self::ipLockKey($user, $ip), 1);
    }

    public static function lockedException(int $seconds): ApiException
    {
        $seconds = max(1, $seconds);

        return new ApiException(
            'Ce compte est temporairement verrouillé après de trop nombreuses tentatives de connexion. Réessayez dans '
                .self::humanDelay($seconds).' ou réinitialisez votre mot de passe.',
            'account_locked',
            423,
            headers: ['Retry-After' => (string) $seconds],
        );
    }

    /**
     * IP tronquée pour l'e-mail de notification et le journal d'audit (jamais l'adresse complète) :
     * 203.0.113.7 → « 203.0.x.x » ; 2001:db8:85a3::1 → « 2001:db8:… ».
     */
    public static function maskIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);

            return $parts[0].'.'.$parts[1].'.x.x';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $parts = explode(':', $ip);

            return $parts[0].':'.($parts[1] ?? '').':…';
        }

        return null;
    }

    /**
     * Pose le verrouillage (15 min), journalise `auth.account_locked` avec une IP tronquée et
     * prévient le titulaire — au plus une fois par heure.
     */
    private function lock(User $account, string $ip, bool $lockIp, bool $lockAccount): void
    {
        $lockedUntil = Carbon::now()->addMinutes(self::LOCK_MINUTES);

        if ($lockAccount) {
            $account->forceFill(['locked_until' => $lockedUntil]);

            // Nouvelle fenêtre après le verrouillage global.
            $this->limiter->clear(self::accountKey($account));
        }

        $account->save();

        if ($lockIp) {
            $this->limiter->hit(self::ipLockKey($account, $ip), self::LOCK_MINUTES * 60);
            $this->limiter->clear(self::accountIpKey($account, $ip));
        }

        $masked = self::maskIp($ip);

        $this->audit->log(
            'auth.account_locked',
            null,
            ['scope' => $lockAccount ? 'account' : 'ip', 'minutes' => self::LOCK_MINUTES],
            $account,
            $masked,
        );

        $notice = self::noticeKey($account);

        if ($this->limiter->tooManyAttempts($notice, 1)) {
            return;
        }

        $this->limiter->hit($notice, self::NOTICE_WINDOW_SECONDS);
        $account->notify(new AccountLockedNotification($lockedUntil, $masked, $lockAccount));
    }

    private static function accountKey(User $user): string
    {
        return 'login-account|'.$user->getKey();
    }

    private static function accountIpKey(User $user, string $ip): string
    {
        return 'login-account-ip|'.$user->getKey().'|'.hash('sha256', $ip);
    }

    private static function ipLockKey(User $user, string $ip): string
    {
        return 'login-lock-ip|'.$user->getKey().'|'.hash('sha256', $ip);
    }

    private static function noticeKey(User $user): string
    {
        return 'login-account-notice|'.$user->getKey();
    }

    private static function humanDelay(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.' seconde'.($seconds > 1 ? 's' : '');
        }

        $minutes = (int) ceil($seconds / 60);

        return $minutes.' minute'.($minutes > 1 ? 's' : '');
    }
}
