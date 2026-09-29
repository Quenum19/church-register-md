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

    /**
     * Libellés des visites, écrits en toutes lettres. SOURCE UNIQUE de ces intitulés :
     * le dashboard, les exports et les e-mails de rapport doivent afficher exactement les
     * mêmes mots (« Première visite » et non « 1re visite » ou « Prospect »).
     *
     * @var array<int, string>
     */
    public const VISIT_LABELS = [
        1 => 'Première visite',
        2 => 'Deuxième visite',
        3 => 'Troisième visite',
    ];

    public function label(): string
    {
        return match ($this) {
            self::Prospect => self::VISIT_LABELS[1],
            self::Recurrent => self::VISIT_LABELS[2],
            self::MembrePotentiel => 'Membre potentiel',
            self::Membre => 'Membre',
        };
    }

    /**
     * Libellé d'un numéro de visite (1 à 3) : « Première visite », « Deuxième visite »…
     */
    public static function visitLabel(int $number): string
    {
        return self::VISIT_LABELS[$number] ?? "Visite n° {$number}";
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
