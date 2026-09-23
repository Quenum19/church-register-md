<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Visitor;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Rétention (contrat d'API §5) :
 * - visiteurs NON membres dont la dernière visite date de plus de 24 mois (visites et notes
 *   supprimées en cascade par les clés étrangères) ;
 * - entrées du journal d'audit de plus de 24 mois : le journal contient des adresses IP et des
 *   e-mails d'administrateurs, il ne doit pas grossir indéfiniment.
 *
 * Une purge qui ne supprime rien n'écrit AUCUNE ligne dans le journal : sinon la tâche
 * quotidienne y ajoute du bruit à vie et masque les vraies purges.
 */
class PurgeVisitorsCommand extends Command
{
    protected $signature = 'visitors:purge
        {--dry-run : Affiche le nombre de visiteurs et de lignes de journal concernés sans rien supprimer}';

    protected $description = 'Purge les visiteurs non membres sans visite depuis 24 mois et le journal d\'audit de plus de 24 mois';

    /** Durée de conservation après la dernière visite. */
    public const RETENTION_MONTHS = 24;

    /** Durée de conservation du journal d'audit. */
    public const AUDIT_RETENTION_MONTHS = 24;

    private const CHUNK = 500;

    public function handle(AuditLogger $audit): int
    {
        $cutoff = self::cutoff(self::RETENTION_MONTHS);
        $auditCutoff = self::cutoff(self::AUDIT_RETENTION_MONTHS);

        $count = $this->eligible($cutoff)->count();
        $auditCount = $this->eligibleLogs($auditCutoff)->count();

        if ($this->option('dry-run')) {
            $this->components->info("Simulation : {$count} visiteur(s) seraient supprimé(s) (dernière visite avant le {$cutoff}).");
            $this->components->info("Simulation : {$auditCount} entrée(s) du journal d'audit seraient supprimée(s) (avant le {$auditCutoff}).");

            return self::SUCCESS;
        }

        $deleted = $this->purgeVisitors($cutoff);

        // Purge du journal AVANT d'y écrire la ligne de cette exécution (qui est d'aujourd'hui).
        $logsDeleted = $this->purgeAuditLogs($auditCutoff);

        // Rien à supprimer : rien à journaliser.
        if ($deleted > 0) {
            $audit->log('visitors.purged', null, [
                'count' => $deleted,
                'cutoff' => $cutoff,
                'retention_months' => self::RETENTION_MONTHS,
            ]);
        }

        if ($logsDeleted > 0) {
            $audit->log('audit_logs.purged', null, [
                'count' => $logsDeleted,
                'cutoff' => $auditCutoff,
                'retention_months' => self::AUDIT_RETENTION_MONTHS,
            ]);
        }

        $this->components->info("{$deleted} visiteur(s) supprimé(s) (dernière visite avant le {$cutoff}).");
        $this->components->info("{$logsDeleted} entrée(s) du journal d'audit supprimée(s) (avant le {$auditCutoff}).");

        return self::SUCCESS;
    }

    /**
     * Suppression par lots, en recalculant la sélection à chaque tour (pas de pagination par décalage).
     */
    private function purgeVisitors(string $cutoff): int
    {
        $deleted = 0;

        do {
            $ids = $this->eligible($cutoff)->orderBy('id')->limit(self::CHUNK)->pluck('id');

            if ($ids->isNotEmpty()) {
                $deleted += DB::transaction(fn (): int => Visitor::query()->whereKey($ids->all())->delete());
            }
        } while ($ids->count() === self::CHUNK);

        return $deleted;
    }

    private function purgeAuditLogs(string $cutoff): int
    {
        $deleted = 0;

        do {
            $removed = (int) $this->eligibleLogs($cutoff)->limit(self::CHUNK)->delete();
            $deleted += $removed;
        } while ($removed === self::CHUNK);

        return $deleted;
    }

    /**
     * Non membres, dont la visite la plus récente est antérieure à la date limite
     * (ou sans aucune visite et créés avant cette date).
     *
     * @return Builder<Visitor>
     */
    private function eligible(string $cutoff): Builder
    {
        return Visitor::query()
            ->whereDoesntHave('member')
            ->whereDoesntHave('visits', fn (Builder $query) => $query->where('visit_date', '>=', $cutoff))
            ->where('created_at', '<', $cutoff);
    }

    /**
     * @return Builder<AuditLog>
     */
    private function eligibleLogs(string $cutoff): Builder
    {
        return AuditLog::query()->where('created_at', '<', $cutoff);
    }

    private static function cutoff(int $months): string
    {
        return CarbonImmutable::now(config('app.timezone'))->subMonthsNoOverflow($months)->toDateString();
    }
}
