<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Pays acceptés pour l'identification et le WhatsApp (contrat d'API §1).
 * `OTHER` = numéro international complet, saisi avec son indicatif (« +44 7… »).
 */
enum PhoneCountry: string
{
    use EnumHelpers;

    case CI = 'CI';
    case SN = 'SN';
    case ML = 'ML';
    case BF = 'BF';
    case GH = 'GH';
    case TG = 'TG';
    case BJ = 'BJ';
    case GN = 'GN';
    case CM = 'CM';
    case CD = 'CD';
    case GA = 'GA';
    case FR = 'FR';
    case BE = 'BE';
    case US = 'US';
    case OTHER = 'OTHER';

    /** Pays proposé par défaut dans les formulaires. */
    public const DEFAULT = self::CI;

    public function label(): string
    {
        return match ($this) {
            self::CI => "Côte d'Ivoire",
            self::SN => 'Sénégal',
            self::ML => 'Mali',
            self::BF => 'Burkina Faso',
            self::GH => 'Ghana',
            self::TG => 'Togo',
            self::BJ => 'Bénin',
            self::GN => 'Guinée',
            self::CM => 'Cameroun',
            self::CD => 'RD Congo',
            self::GA => 'Gabon',
            self::FR => 'France',
            self::BE => 'Belgique',
            self::US => 'États-Unis',
            self::OTHER => 'Autre pays',
        };
    }

    /**
     * Indicatif international sans le « + » (null pour OTHER).
     */
    public function dialCode(): ?string
    {
        return match ($this) {
            self::CI => '225',
            self::SN => '221',
            self::ML => '223',
            self::BF => '226',
            self::GH => '233',
            self::TG => '228',
            self::BJ => '229',
            self::GN => '224',
            self::CM => '237',
            self::CD => '243',
            self::GA => '241',
            self::FR => '33',
            self::BE => '32',
            self::US => '1',
            self::OTHER => null,
        };
    }

    /**
     * Région libphonenumber (null pour OTHER : le numéro porte son propre indicatif).
     */
    public function region(): ?string
    {
        return $this === self::OTHER ? null : $this->value;
    }
}
