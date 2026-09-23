<?php

namespace App\Support;

use App\Services\PhoneNumberService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Limiteurs de débit nommés (enregistrés par AppServiceProvider) et clés réutilisables.
 *
 * Utilisation dans les routes : ->middleware('throttle:identify'), 'throttle:login', etc.
 * Les clés sont exposées en statique pour un usage manuel via la façade RateLimiter
 * (ex. ne compter que les échecs de connexion, ou que les identifications abouties).
 */
final class RateLimits
{
    public const PUBLIC = 'public';

    public const IDENTIFY = 'identify';

    public const LOGIN = 'login';

    public const ADMIN = 'admin';

    public const REPORT_TEST = 'report-test';

    /** Requêtes par minute et par IP sur /api/public (le Wi-Fi de l'église partage une IP publique). */
    public const PUBLIC_PER_MINUTE = 300;

    /** Requêtes par minute et par IP sur /api/health (sonde de disponibilité). */
    public const HEALTH_PER_MINUTE = 60;

    /**
     * Identifications par heure et par numéro normalisé. Seules les identifications qui
     * ABOUTISSENT à l'émission d'un jeton sont comptées (App\Services\Journey\IdentificationService) :
     * une personne dont la visite du jour est déjà enregistrée n'épuise pas son quota.
     */
    public const IDENTIFY_PER_HOUR = 5;

    public const IDENTIFY_WINDOW_SECONDS = 3600;

    /**
     * Identifications par heure et par IP (variable RATE_LIMIT_IDENTIFY_PER_IP).
     *
     * Sans elle, une seule IP peut tester ~18 000 numéros par heure (oracle de présence).
     * ATTENTION : tous les téléphones du Wi-Fi de l'église sortent sur UNE adresse publique ;
     * la valeur doit absorber un dimanche chargé (largement plus de 100 identifications/heure).
     */
    public const IDENTIFY_PER_IP_PER_HOUR = 200;

    /** Tentatives de connexion par couple (e-mail + IP) sur la fenêtre ci-dessous. */
    public const LOGIN_MAX_ATTEMPTS = 5;

    public const LOGIN_DECAY_MINUTES = 15;

    /** Requêtes par minute et par utilisateur sur /api/admin. */
    public const ADMIN_PER_MINUTE = 120;

    /** E-mails de test par heure et par utilisateur (POST /api/admin/report-recipients/test). */
    public const REPORT_TEST_PER_HOUR = 5;

    public static function register(): void
    {
        RateLimiter::for(self::PUBLIC, static fn (Request $request): Limit => Limit::perMinute(self::PUBLIC_PER_MINUTE)
            ->by('public|'.$request->ip()));

        // Limite PAR IP. La limite par numéro est appliquée manuellement (voir IDENTIFY_PER_HOUR) :
        // le middleware compterait aussi les requêtes refusées, ce qui bloquerait une personne
        // légitime en 5 essais.
        RateLimiter::for(self::IDENTIFY, static fn (Request $request): Limit => Limit::perHour(self::identifyPerIpPerHour())
            ->by('identify-ip|'.$request->ip()));

        RateLimiter::for(self::LOGIN, static fn (Request $request): Limit => Limit::perMinutes(self::LOGIN_DECAY_MINUTES, self::LOGIN_MAX_ATTEMPTS)
            ->by(self::loginKey(self::stringInput($request, 'email', ''), (string) $request->ip())));

        RateLimiter::for(self::ADMIN, static function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(self::ADMIN_PER_MINUTE)->by($user !== null
                ? 'admin|user:'.$user->getAuthIdentifier()
                : 'admin|ip:'.$request->ip());
        });

        RateLimiter::for(self::REPORT_TEST, static function (Request $request): Limit {
            $user = $request->user();

            return Limit::perHour(self::REPORT_TEST_PER_HOUR)->by($user !== null
                ? 'report-test|user:'.$user->getAuthIdentifier()
                : 'report-test|ip:'.$request->ip());
        });
    }

    /**
     * Limite par IP de l'identification, configurable (RATE_LIMIT_IDENTIFY_PER_IP).
     */
    public static function identifyPerIpPerHour(): int
    {
        $value = config('rate-limits.identify_per_ip_per_hour', self::IDENTIFY_PER_IP_PER_HOUR);

        return is_numeric($value) ? max(1, (int) $value) : self::IDENTIFY_PER_IP_PER_HOUR;
    }

    /**
     * Clé de limitation par numéro : HMAC du numéro E.164 (jamais le numéro en clair).
     */
    public static function identifyNumberKey(string $e164): string
    {
        return 'identify|'.PhoneNumberService::hash($e164);
    }

    /**
     * Clé de limitation d'identification à partir d'une saisie brute,
     * ou null si le numéro est invalide.
     */
    public static function identifyKey(string $country, string $phone): ?string
    {
        $e164 = PhoneNumberService::normalize($country, $phone);

        return $e164 === null ? null : self::identifyNumberKey($e164);
    }

    /**
     * Clé de limitation de connexion : e-mail en minuscules + IP.
     */
    public static function loginKey(string $email, string $ip): string
    {
        return 'login|'.hash('sha256', Str::lower(trim($email)).'|'.$ip);
    }

    private static function stringInput(Request $request, string $key, string $default): string
    {
        $value = $request->input($key, $default);

        return is_string($value) ? $value : $default;
    }
}
