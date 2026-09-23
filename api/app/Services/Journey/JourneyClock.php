<?php

namespace App\Services\Journey;

use Carbon\CarbonImmutable;

/**
 * Horloge du parcours visiteur : « aujourd'hui » = date dans le fuseau applicatif
 * (APP_TIMEZONE = Africa/Abidjan, contrat §0), comme FamilyRotationService.
 */
final class JourneyClock
{
    public const DEFAULT_TIMEZONE = 'Africa/Abidjan';

    public static function timezone(): string
    {
        $timezone = config('app.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : self::DEFAULT_TIMEZONE;
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    /**
     * Date du jour au format YYYY-MM-DD (colonne `visits.visit_date`).
     */
    public static function today(): string
    {
        return self::now()->toDateString();
    }
}
