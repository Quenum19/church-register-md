<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Statut d'un visiteur. Écrit exclusivement par App\Services\VisitorStatusService.
 */
enum VisitorStatus: string
{
    use EnumHelpers;

    case Prospect = 'prospect';
    case Recurrent = 'recurrent';
    case MembrePotentiel = 'membre_potentiel';
    case Membre = 'membre';

    public function label(): string
    {
        return match ($this) {
            self::Prospect => 'Prospect',
            self::Recurrent => 'Récurrent',
            self::MembrePotentiel => 'Membre potentiel',
            self::Membre => 'Membre',
        };
    }

    /**
     * Statut correspondant à un nombre de visites (hors conversion en membre).
     */
    public static function fromVisitCount(int $count): self
    {
        return match (true) {
            $count >= 3 => self::MembrePotentiel,
            $count === 2 => self::Recurrent,
            default => self::Prospect,
        };
    }
}
