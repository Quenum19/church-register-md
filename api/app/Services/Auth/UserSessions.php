<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * Révocation des sessions d'un compte (sessions en base, table `sessions`).
 *
 * Utilisé à la désactivation / suppression d'un compte, au changement et à la réinitialisation
 * du mot de passe. Le middleware `active` et la vérification du hachage de mot de passe de
 * Sanctum (AuthenticateSession) couvrent en complément tout autre pilote de session.
 */
class UserSessions
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Config $config,
    ) {}

    /**
     * Supprime toutes les sessions du compte.
     */
    public function destroyAll(User $user): int
    {
        return $this->table()->where('user_id', $user->getKey())->delete();
    }

    /**
     * Supprime toutes les sessions du compte sauf celle indiquée (la session courante).
     */
    public function destroyOthers(User $user, string $keepSessionId): int
    {
        return $this->table()
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $keepSessionId)
            ->delete();
    }

    private function table(): Builder
    {
        $connection = $this->config->get('session.connection');
        $table = $this->config->get('session.table', 'sessions');

        return $this->db
            ->connection(is_string($connection) ? $connection : null)
            ->table(is_string($table) ? $table : 'sessions');
    }
}
