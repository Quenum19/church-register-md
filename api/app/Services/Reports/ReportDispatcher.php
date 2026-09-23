<?php

namespace App\Services\Reports;

use App\Exceptions\ApiException;
use App\Mail\MonthlyReportMail;
use App\Models\ReportDispatch;
use App\Models\ReportRecipient;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envoi des rapports mensuels par e-mail (manuel depuis le dashboard, ou automatique par la
 * commande reports:dispatch).
 *
 * - Destinataires : actifs de la famille du mois + globaux (family_id NULL), dédoublonnés,
 *   mis en COPIE CACHÉE (`to` = adresse d'expédition de l'Église) : ils ne se voient pas
 *   mutuellement. La ligne report_dispatches enregistre la liste réelle.
 * - Envoi SYNCHRONE via le mailer configuré (Brevo en production) : l'échec du transport est
 *   connu immédiatement (503 `mail_failed`) et AUCUNE ligne report_dispatches n'est écrite,
 *   ce qui permet le rattrapage automatique le lendemain.
 * - Idempotence : unique(year, month) dans report_dispatches + verrou de cache par mois, qui
 *   sérialise un envoi manuel et l'envoi automatique simultanés.
 */
class ReportDispatcher
{
    /** Durée de vie du verrou d'envoi d'un mois (secondes). */
    public const LOCK_SECONDS = 120;

    /** Attente maximale du verrou quand un autre envoi du même mois est en cours (secondes). */
    public const LOCK_WAIT_SECONDS = 10;

    public const MAIL_FAILED_MESSAGE = "L'e-mail n'a pas pu être envoyé. Réessayez dans quelques minutes ; si le problème persiste, "
        ."vérifiez la configuration de l'envoi d'e-mails.";

    public function __construct(
        private readonly MonthlyReportService $reports,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly int $lockWaitSeconds = self::LOCK_WAIT_SECONDS,
    ) {}

    /**
     * Adresses destinataires du rapport d'une famille (NULL : mois sans rotation => globaux seuls),
     * en minuscules et sans doublon.
     *
     * @return list<string>
     */
    public function recipientsFor(?int $familyId): array
    {
        return ReportRecipient::query()
            ->forFamily($familyId)
            ->orderBy('id')
            ->pluck('email')
            ->map(static fn (string $email): string => Str::lower(trim($email)))
            ->filter(static fn (string $email): bool => $email !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Envoie le rapport (année, mois) et enregistre l'envoi.
     *
     * @param  User|null  $sender  auteur de l'envoi manuel ; null = envoi automatique (tâche planifiée)
     *
     * @throws ApiException 409 `already_sent` (sans $force), 409 `send_in_progress`,
     *                      422 `no_recipients`, 503 `mail_failed`
     */
    public function send(int $year, int $month, ?User $sender = null, bool $force = false): ReportDispatch
    {
        try {
            return Cache::lock("reports:dispatch:{$year}-{$month}", self::LOCK_SECONDS)
                ->block($this->lockWaitSeconds, fn (): ReportDispatch => $this->sendLocked($year, $month, $sender, $force));
        } catch (LockTimeoutException) {
            throw new ApiException(
                'Un envoi de ce rapport est déjà en cours. Réessayez dans quelques instants.',
                'send_in_progress',
                409,
            );
        }
    }

    /**
     * Envoie un e-mail de façon synchrone. Toute erreur du transport (Brevo indisponible, clé
     * invalide, expéditeur non validé…) est journalisée en détail et remontée en 503 générique.
     *
     * @param  list<string>  $to
     * @param  array<string, mixed>  $context  contexte non sensible ajouté au journal
     * @param  bool  $blind  destinataires en copie cachée (voir sendLocked)
     *
     * @throws ApiException 503 `mail_failed`
     */
    public function deliver(Mailable $mail, array $to, array $context = [], bool $blind = false): void
    {
        $pending = $blind ? Mail::to($this->senderAddress())->bcc($to) : Mail::to($to);

        try {
            $pending->send($mail);
        } catch (Throwable $e) {
            Log::error("Échec de l'envoi d'un e-mail.", [
                ...$context,
                'mailable' => $mail::class,
                'recipients_count' => count($to),
                'exception' => $e,
            ]);

            throw new ApiException(self::MAIL_FAILED_MESSAGE, 'mail_failed', 503);
        }
    }

    /**
     * Adresse d'expédition configurée (MAIL_FROM_ADDRESS), utilisée comme destinataire visible
     * d'un envoi en copie cachée.
     */
    private function senderAddress(): string
    {
        $address = config('mail.from.address');

        if (! is_string($address) || $address === '') {
            throw new ApiException(self::MAIL_FAILED_MESSAGE, 'mail_failed', 503);
        }

        return $address;
    }

    private function sendLocked(int $year, int $month, ?User $sender, bool $force): ReportDispatch
    {
        $existing = ReportDispatch::query()->forMonth($year, $month)->first();

        if ($existing !== null && ! $force) {
            throw new ApiException('Ce rapport a déjà été envoyé.', 'already_sent', 409);
        }

        $report = $this->reports->forMonth($year, $month);
        $recipients = $this->recipientsFor($report->family?->id);

        if ($recipients === []) {
            throw new ApiException(
                "Aucun destinataire actif n'est configuré pour ce rapport.",
                'no_recipients',
                422,
            );
        }

        // Copie cachée : les destinataires d'un rapport (responsables de familles, secrétariat,
        // pasteur) n'ont pas à connaître les adresses les uns des autres. `to` = l'adresse
        // d'expédition de l'Église, pour que le message reste correctement adressé.
        $this->deliver(
            new MonthlyReportMail($report, $this->settings->churchName()),
            $recipients,
            ['report' => sprintf('%04d-%02d', $year, $month)],
            blind: true,
        );

        // L'e-mail est parti : l'envoi est enregistré en premier (hors transaction avec le journal)
        // pour qu'un incident ultérieur ne provoque jamais de second envoi automatique.
        $dispatch = ReportDispatch::query()->updateOrCreate(
            ['year' => $year, 'month' => $month],
            [
                'family_id' => $report->family?->id,
                'sent_at' => now(),
                'recipients' => $recipients,
                'sent_by' => $sender?->id,
            ],
        );

        $this->audit->log('report.sent', $dispatch, [
            'year' => $year,
            'month' => $month,
            'family_id' => $report->family?->id,
            'recipients_count' => count($recipients),
            'resent' => $existing !== null,
            'automatic' => $sender === null,
        ], $sender);

        return $dispatch;
    }
}
