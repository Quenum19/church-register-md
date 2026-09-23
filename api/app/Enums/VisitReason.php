<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Motivation de la 3e visite (champ `visit_reason`).
 */
enum VisitReason: string
{
    use EnumHelpers;

    case NouveauResident = 'nouveau_resident';
    case DevenirMembre = 'devenir_membre';
    case Vacances = 'vacances';
    case Autres = 'autres';

    public function label(): string
    {
        return match ($this) {
            self::NouveauResident => 'Nouveau résident dans la ville',
            self::DevenirMembre => 'Désir de devenir membre',
            self::Vacances => 'De passage / vacances',
            self::Autres => 'Autre raison',
        };
    }
}
