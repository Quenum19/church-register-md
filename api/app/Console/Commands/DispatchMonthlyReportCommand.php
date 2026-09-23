<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Models\ReportDispatch;
use App\Services\Reports\MonthlyReportService;
use App\Services\Reports\ReportDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Envoi automatique du rapport mensuel (planifié chaque jour à 08:00, routes/console.php).
 *
 * Idempotente : si le rapport du mois figure déjà dans report_dispatches, rien n'est envoyé.
 * Un envoi manqué le 1er (serveur arrêté, erreur Brevo) part donc au passage suivant :
 * rattrapage automatique, sans double envoi. L'envoi est tracé avec sent_by = NULL
 * et journalisé (report.sent, meta.automatic = true).
 */
class DispatchMonthlyReportCommand extends Command
{
    protected $signature = 'reports:dispatch
        {--month= : Mois du rapport au format YYYY-MM (par défaut : le mois précédent)}';

    protected $description = "Envoie par e-mail le rapport mensuel des visiteurs s'il n'a pas encore été envoyé";

    public function handle(MonthlyReportService $reports, ReportDispatcher $dispatcher): int
    {
        $period = $this->period();

        if ($period === null) {
            $this->components->error('Option --month invalide : mois écoulé attendu, au format YYYY-MM (ex. 2026-08).');

            return self::INVALID;
        }

        [$year, $month] = $period;
        $label = sprintf('%04d-%02d', $year, $month);

        if (! $reports->exists($year, $month)) {
            $this->components->info("Aucun rapport pour {$label} (mois antérieur à la première visite enregistrée).");

            return self::SUCCESS;
        }

        $existing = ReportDispatch::query()->forMonth($year, $month)->first();

        if ($existing !== null) {
            $this->components->info("Rapport de {$label} déjà envoyé le {$existing->sent_at->format('d/m/Y à H:i')} : rien à faire.");

            return self::SUCCESS;
        }

        try {
            $dispatch = $dispatcher->send($year, $month);
        } catch (ApiException $e) {
            return $this->handleFailure($e, $year, $month, $label);
        }

        $count = count($dispatch->recipients);
        $this->components->info("Rapport de {$label} envoyé à {$count} destinataire(s).");

        return self::SUCCESS;
    }

    private function handleFailure(ApiException $e, int $year, int $month, string $label): int
    {
        switch ($e->errorCode) {
            case 'already_sent':
            case 'send_in_progress':
                // Envoi manuel simultané : le rapport est (ou va être) envoyé.
                $this->components->info("Rapport de {$label} déjà envoyé ou en cours d'envoi : rien à faire.");

                return self::SUCCESS;

            case 'no_recipients':
                Log::warning('Rapport mensuel non envoyé : aucun destinataire actif.', ['year' => $year, 'month' => $month]);
                $this->components->warn("Rapport de {$label} non envoyé : aucun destinataire actif. Nouvel essai au prochain passage.");

                return self::SUCCESS;

            default:
                // mail_failed : détail déjà journalisé par ReportDispatcher ; nouvel essai au prochain passage.
                $this->components->error("Échec de l'envoi du rapport de {$label} : {$e->getMessage()}");

                return self::FAILURE;
        }
    }

    /**
     * Mois demandé (--month) ou mois précédent ; null si le format est invalide ou si le mois
     * n'est pas terminé (mois courant ou futur).
     *
     * @return array{0: int, 1: int}|null
     */
    private function period(): ?array
    {
        $timezone = config('app.timezone');
        $currentMonth = CarbonImmutable::now(is_string($timezone) ? $timezone : 'Africa/Abidjan')->startOfMonth();
        $option = $this->option('month');

        if ($option === null || $option === '') {
            $previous = $currentMonth->subMonthNoOverflow();

            return [$previous->year, $previous->month];
        }

        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $option, $matches) !== 1) {
            return null;
        }

        [$year, $month] = [(int) $matches[1], (int) $matches[2]];

        if ($year * 12 + $month >= $currentMonth->year * 12 + $currentMonth->month) {
            return null;
        }

        return [$year, $month];
    }
}
