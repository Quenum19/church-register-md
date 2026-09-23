<?php

namespace App\Services;

use App\Enums\VisitorStatus;
use App\Models\Visitor;

/**
 * SEUL endroit du code qui calcule et écrit `visitors.status`.
 *
 * À appeler dans la même transaction que l'insertion d'une visite, la conversion
 * ou l'annulation d'une conversion (idéalement après un lockForUpdate du visiteur).
 */
class VisitorStatusService
{
    /**
     * Statut attendu d'après la base : membre si une ligne `members` existe,
     * sinon selon le nombre de visites (1 = prospect, 2 = recurrent, 3 = membre_potentiel).
     */
    public function compute(Visitor $visitor): VisitorStatus
    {
        if ($visitor->member()->exists()) {
            return VisitorStatus::Membre;
        }

        return VisitorStatus::fromVisitCount($visitor->visits()->count());
    }

    /**
     * Recalcule le statut et l'enregistre s'il a changé.
     */
    public function refresh(Visitor $visitor): VisitorStatus
    {
        $status = $this->compute($visitor);

        if ($visitor->status !== $status) {
            // `status` est hors $fillable : écriture explicite et volontaire.
            $visitor->forceFill(['status' => $status])->save();
        }

        return $status;
    }
}
