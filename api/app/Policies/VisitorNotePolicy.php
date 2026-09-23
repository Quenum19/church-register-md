<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VisitorNote;
use Illuminate\Auth\Access\Response;

/**
 * Notes de suivi (contrat d'API §1) : une note n'est supprimable que par son auteur ou un super_admin.
 * Découverte automatiquement par Laravel (App\Models\VisitorNote => App\Policies\VisitorNotePolicy).
 */
class VisitorNotePolicy
{
    public function delete(User $user, VisitorNote $note): Response
    {
        if (! $user->is_active) {
            return Response::deny();
        }

        return $user->isSuperAdmin() || ($note->user_id !== null && $note->user_id === $user->id)
            ? Response::allow()
            : Response::deny('Seul l\'auteur de la note ou un super administrateur peut la supprimer.');
    }
}
