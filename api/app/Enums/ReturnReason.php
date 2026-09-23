<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Raisons du retour (visite 2, champ `return_reasons[]`).
 */
enum ReturnReason: string
{
    use EnumHelpers;

    case Enseignement = 'enseignement';
    case ChaleurFraternelle = 'chaleur_fraternelle';
    case LouangeAdoration = 'louange_adoration';
    case Accueil = 'accueil';
    case Autres = 'autres';

    public function label(): string
    {
        return match ($this) {
            self::Enseignement => "L'enseignement de la Parole",
            self::ChaleurFraternelle => 'La chaleur fraternelle',
            self::LouangeAdoration => "La louange et l'adoration",
            self::Accueil => "L'accueil reçu",
            self::Autres => 'Autre(s) raison(s)',
        };
    }
}
