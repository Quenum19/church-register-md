<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Objet `User` du contrat d'API §3.
 *
 * Liste blanche stricte : jamais de mot de passe, secret 2FA, codes de récupération,
 * compteur d'échecs ni date de verrouillage.
 *
 * @property User $resource
 */
class UserResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, email: string, role: string, is_active: bool, two_factor_enabled: bool, last_login_at: string|null, created_at: string|null, invitation_pending: bool}
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'is_active' => $user->is_active,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'invitation_pending' => $user->isInvitationPending(),
        ];
    }
}
