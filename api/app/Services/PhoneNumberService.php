<?php

namespace App\Services;

use App\Enums\PhoneCountry;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use RuntimeException;

/**
 * Normalisation des numéros de téléphone en E.164 (libphonenumber).
 *
 * Méthodes statiques : utilisables telles quelles (limiteur de débit, règles de validation)
 * comme sur une instance injectée.
 */
class PhoneNumberService
{
    /** Longueur maximale acceptée pour une saisie brute (au-delà : invalide). */
    public const MAX_RAW_LENGTH = 32;

    /**
     * Normalise un numéro saisi en E.164 (« +2250700000000 »), ou null s'il est invalide.
     *
     * - `$country` : code du contrat (CI, SN, …, OTHER), insensible à la casse.
     * - Pays connu : le numéro doit être valide pour cette région ; le « 0 » initial local
     *   (GH, FR, BE…) est géré par libphonenumber. Pour la CI, les 10 chiffres (« 07 00 00 00 00 »)
     *   sont le numéro national complet depuis 2021.
     * - OTHER : numéro international complet avec indicatif (« +44 7… » ou « 0044 7… »).
     */
    public static function normalize(string $country, string $raw): ?string
    {
        $phoneCountry = PhoneCountry::tryFrom(strtoupper(trim($country)));
        $raw = trim($raw);

        if ($phoneCountry === null || $raw === '' || mb_strlen($raw) > self::MAX_RAW_LENGTH) {
            return null;
        }

        // Chiffres, espaces (y compris insécables) et séparateurs usuels uniquement :
        // ni lettres, ni extension, ni tabulation ou retour à la ligne.
        if (preg_match('/^\+?[0-9 \x{00A0}().\-]+$/u', $raw) !== 1) {
            return null;
        }

        $region = $phoneCountry->region();

        if ($region === null) {
            if (str_starts_with($raw, '00')) {
                $raw = '+'.substr($raw, 2);
            }

            if (! str_starts_with($raw, '+')) {
                return null;
            }
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse($raw, $region ?? 'ZZ');
        } catch (NumberParseException) {
            return null;
        }

        $valid = $region === null
            ? $util->isValidNumber($number)
            : $util->isValidNumberForRegion($number, $region);

        return $valid ? $util->format($number, PhoneNumberFormat::E164) : null;
    }

    /**
     * Empreinte HMAC-SHA256 (clé = APP_KEY) d'un numéro E.164.
     * Sert de clé de limitation de débit sans stocker le numéro en clair dans le cache.
     */
    public static function hash(string $e164): string
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException("APP_KEY n'est pas définie.");
        }

        return hash_hmac('sha256', $e164, $key);
    }

    /**
     * Format international lisible (« +225 07 00 00 00 00 ») pour les exports et e-mails.
     * Renvoie la valeur telle quelle si elle n'est pas analysable.
     */
    public static function formatInternational(string $e164): string
    {
        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse($e164, 'ZZ');
        } catch (NumberParseException) {
            return $e164;
        }

        // libphonenumber écrirait « +225 07 79 05 5423 » (dernier groupe à quatre chiffres) alors
        // que le dashboard affiche des groupes de deux. Les exports et les e-mails s'alignent sur
        // l'affichage vu par les administrateurs. Les chiffres sont repris de la forme E.164 :
        // getNationalNumber() est un entier, il perdrait le zéro initial des numéros ivoiriens.
        $code = (string) $number->getCountryCode();
        $national = substr($util->format($number, PhoneNumberFormat::E164), 1 + strlen($code));

        if ($national === '') {
            return $e164;
        }

        $groups = strlen($national) % 2 === 0
            ? str_split($national, 2)
            : array_merge([$national[0]], str_split(substr($national, 1), 2));

        return '+'.$code.' '.implode(' ', $groups);
    }
}
