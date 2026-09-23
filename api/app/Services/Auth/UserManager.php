<?php

namespace App\Services\Auth;

use App\Enums\Role;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Arr;

/**
 * Gestion des administrateurs (contrat d'API §1 et §4, ability `users.manage`).
 *
 * Règles (409) :
 * - `forbidden_self_change` : impossible de se rétrograder, de se désactiver ou de se supprimer ;
 * - `last_super_admin` : impossible de rétrograder, désactiver ou supprimer le dernier super_admin ACTIF.
 *
 * Les lignes des super_admins sont verrouillées (SELECT … FOR UPDATE, toujours dans le même ordre)
 * avant toute vérification : deux super_admins ne peuvent pas se rétrograder mutuellement en même temps.
 */
class UserManager
{
    /** Champs modifiables d'un administrateur. */
    private const EDITABLE = ['name', 'email', 'role', 'is_active'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PasswordResets $resets,
        private readonly UserSessions $sessions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Crée un compte sans mot de passe (invitation en attente) et envoie l'invitation (48 h).
     *
     * @param  array{name: string, email: string, role: string}  $data
     */
    public function invite(array $data): User
    {
        return $this->db->transaction(function () use ($data): User {
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $data['role'],
                'is_active' => true,
            ]);
            $user->password = null;
            $user->save();

            $this->audit->log('user.created', $user, ['role' => $user->role->value]);
            $this->resets->sendInvitation($user);

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $data  sous-ensemble validé de { name, email, role, is_active }
     *
     * @throws ApiException 409 forbidden_self_change / last_super_admin
     */
    public function update(User $actor, User $target, array $data): User
    {
        return $this->db->transaction(function () use ($actor, $target, $data): User {
            $this->lockSuperAdmins();
            $user = $this->lockUser($target);
            $originalEmail = $user->email;
            $wasActiveSuperAdmin = $user->isSuperAdmin() && $user->is_active;

            $user->fill(Arr::only($data, self::EDITABLE));

            $roleChanged = $user->isDirty('role');
            $deactivated = $user->isDirty('is_active') && ! $user->is_active;

            if ($user->is($actor) && ($roleChanged || $deactivated)) {
                throw self::selfChange();
            }

            if ($wasActiveSuperAdmin && (($roleChanged && ! $user->isSuperAdmin()) || $deactivated)) {
                $this->ensureAnotherActiveSuperAdmin($user);
            }

            $fields = array_values(array_intersect(self::EDITABLE, array_keys($user->getDirty())));

            if ($fields === []) {
                return $user;
            }

            $user->save();

            if ($deactivated) {
                $this->sessions->destroyAll($user);

                // Un lien de réinitialisation ou d'invitation déjà envoyé ne doit plus servir :
                // sinon le compte que l'on vient de fermer se rouvre tout seul.
                $this->resets->forget($user->email);
            }

            if (in_array('email', $fields, true)) {
                // Un lien envoyé à l'ancienne adresse ne doit plus servir.
                $this->resets->forget($originalEmail);

                // Jamais de nouvelle invitation vers un compte qu'on vient de désactiver.
                if ($user->isInvitationPending() && $user->is_active) {
                    $this->resets->sendInvitation($user);
                }
            }

            $meta = ['fields' => $fields];

            if (in_array('role', $fields, true)) {
                $meta['role'] = $user->role->value;
            }

            if (in_array('is_active', $fields, true)) {
                $meta['is_active'] = $user->is_active;
            }

            $this->audit->log('user.updated', $user, $meta);

            return $user;
        });
    }

    /**
     * @throws ApiException 409 forbidden_self_change / last_super_admin
     */
    public function delete(User $actor, User $target): void
    {
        if ($target->is($actor)) {
            throw self::selfChange();
        }

        $this->db->transaction(function () use ($target): void {
            $this->lockSuperAdmins();
            $user = $this->lockUser($target);

            if ($user->isSuperAdmin() && $user->is_active) {
                $this->ensureAnotherActiveSuperAdmin($user);
            }

            $this->audit->log('user.deleted', $user, ['email' => $user->email, 'role' => $user->role->value]);
            $this->sessions->destroyAll($user);
            $this->resets->forget($user->email);
            $user->delete();
        });
    }

    /**
     * Renvoie l'invitation (nouveau lien 48 h, l'ancien est invalidé).
     *
     * @throws ApiException 409 invitation_not_pending si le compte a déjà choisi son mot de passe
     */
    public function resendInvitation(User $target): void
    {
        $this->db->transaction(function () use ($target): void {
            $user = $this->lockUser($target);

            if (! $user->isInvitationPending() || ! $user->is_active) {
                throw new ApiException(
                    $user->is_active
                        ? 'Ce compte a déjà choisi son mot de passe : aucune invitation à renvoyer.'
                        : "Ce compte est désactivé : réactivez-le avant de renvoyer l'invitation.",
                    'invitation_not_pending',
                    409,
                );
            }

            $this->resets->sendInvitation($user);
            $this->audit->log('user.updated', $user, ['invitation_resent' => true]);
        });
    }

    private function lockSuperAdmins(): void
    {
        User::query()
            ->where('role', Role::SuperAdmin->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');
    }

    private function lockUser(User $user): User
    {
        return User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }

    private function ensureAnotherActiveSuperAdmin(User $user): void
    {
        $others = User::query()
            ->where('role', Role::SuperAdmin->value)
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->count();

        if ($others === 0) {
            throw new ApiException(
                'Action impossible : il doit toujours rester au moins un super administrateur actif.',
                'last_super_admin',
                409,
            );
        }
    }

    private static function selfChange(): ApiException
    {
        return new ApiException(
            'Vous ne pouvez pas modifier votre propre rôle, vous désactiver ni supprimer votre propre compte.',
            'forbidden_self_change',
            409,
        );
    }
}
