<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Comment le visiteur a connu l'église (visite 1, champ `source`).
 */
enum Source: string
{
    use EnumHelpers;

    case InviteMembre = 'invite_membre';
    case SaintEsprit = 'saint_esprit';
    case ReseauxSociaux = 'reseaux_sociaux';
    case AfficheTract = 'affiche_tract';
    case BoucheAOreille = 'bouche_a_oreille';
    case Passage = 'passage';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::InviteMembre => 'Invité(e) par un membre',
            self::SaintEsprit => 'Saint-Esprit',
            self::ReseauxSociaux => 'Réseaux sociaux',
            self::AfficheTract => 'Affiche / tract',
            self::BoucheAOreille => 'Bouche-à-oreille',
            self::Passage => "Passage devant l'église",
            self::Autre => 'Autre',
        };
    }
}
