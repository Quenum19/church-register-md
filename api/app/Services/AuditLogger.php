<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Écriture du journal d'audit (table `audit_logs`).
 *
 * Actions du contrat : auth.login, auth.login_failed, auth.account_locked, auth.logout,
 * auth.password_changed, auth.two_factor_enabled, auth.two_factor_disabled, visitor.updated,
 * visitor.deleted, visitor.converted, visitor.unconverted, note.created, note.deleted,
 * user.created, user.updated, user.deleted, settings.updated, rotation.updated,
 * recipient.created, recipient.updated, recipient.deleted, report.sent, report.test_sent,
 * export.csv, export.xlsx, export.pdf. Ajouts du socle : visitors.purged et audit_logs.purged
 * (commandes de rétention).
 */
class AuditLogger
{
    public function __construct(private readonly Application $app) {}

    /**
     * Enregistre une action. L'utilisateur et l'IP sont déduits de la requête courante
     * sauf si `$user` est fourni (ex. connexion : l'utilisateur n'est pas encore sur la requête).
     *
     * @param  array<string, mixed>  $meta  données non sensibles uniquement (jamais de mot de passe ni de jeton)
     * @param  string|null  $ip  IP à journaliser au lieu de celle de la requête. Les échecs
     *                           d'authentification y écrivent une IP TRONQUÉE (auth.login_failed,
     *                           auth.account_locked) : l'appelant n'est pas authentifié et son
     *                           adresse complète n'a pas à être conservée.
     */
    public function log(string $action, ?Model $subject = null, array $meta = [], ?User $user = null, ?string $ip = null): AuditLog
    {
        $request = $this->request();
        $user ??= $this->currentUser($request);

        return AuditLog::query()->create([
            'user_id' => $user?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'ip' => $ip ?? $this->ip($request),
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    private function request(): ?Request
    {
        return $this->app->bound('request') ? $this->app->make(Request::class) : null;
    }

    private function currentUser(?Request $request): ?User
    {
        $user = $request?->user();

        return $user instanceof User ? $user : null;
    }

    private function ip(?Request $request): ?string
    {
        // En ligne de commande (cron, artisan), la requête est synthétique : pas d'IP significative.
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            return null;
        }

        return $request?->ip();
    }
}
