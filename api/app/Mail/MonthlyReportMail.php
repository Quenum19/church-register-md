<?php

namespace App\Mail;

use App\Mail\Concerns\RendersPlainTextAlternative;
use App\Services\PhoneNumberService;
use App\Services\Reports\MonthlyReport;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Rapport mensuel des visiteurs de la famille de service (HTML en tableaux + version texte).
 *
 * Envoyé de façon synchrone (pas de ShouldQueue) : l'appelant doit savoir immédiatement si le
 * transport a échoué pour ne pas marquer le rapport comme envoyé (voir ReportDispatcher).
 * Toutes les données sont échappées par Blade ({{ }}) dans les vues mail/reports/*.
 */
class MonthlyReportMail extends Mailable
{
    use RendersPlainTextAlternative;

    public function __construct(
        public readonly MonthlyReport $report,
        public readonly string $churchName,
    ) {
        $this->locale('fr');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Rapport des visiteurs — %s — Famille %s', $this->report->monthLabel(), $this->report->familyName()),
            tags: ['rapport-mensuel'],
        );
    }

    public function content(): Content
    {
        $report = $this->report;

        return new Content(
            view: 'mail.reports.monthly',
            text: 'mail.reports.monthly-text',
            with: [
                'title' => 'Rapport des visiteurs — '.$report->monthLabel(),
                'monthLabel' => $report->monthLabel(),
                'familyName' => $report->familyName(),
                'hasFamily' => $report->family !== null,
                'inProgress' => $report->inProgress,
                'counts' => $report->counts,
                'tiles' => [
                    ['label' => '1re visite', 'value' => $report->counts['v1']],
                    ['label' => '2e visite', 'value' => $report->counts['v2']],
                    ['label' => '3e visite', 'value' => $report->counts['v3']],
                    ['label' => 'Total', 'value' => $report->counts['total']],
                    ['label' => 'Conversions', 'value' => $report->counts['conversions']],
                ],
                'visitors' => array_map(static fn (array $visitor): array => [
                    'full_name' => $visitor['full_name'],
                    'phone' => PhoneNumberService::formatInternational($visitor['phone']),
                    'visit' => self::ordinal($visitor['visit_number']).' visite',
                    'date' => $visitor['visit_date']->format('d/m/Y'),
                ], $report->visitors),
                'conversions' => array_map(static fn (array $conversion): array => [
                    'full_name' => $conversion['full_name'],
                    'date' => $conversion['converted_at']->format('d/m/Y'),
                ], $report->conversions),
                'reportUrl' => self::reportUrl($report->year, $report->month),
            ],
        );
    }

    /**
     * Lien vers le rapport dans le dashboard : {APP_URL}/admin/rapports/{année}/{mois}.
     */
    public static function reportUrl(int $year, int $month): string
    {
        $base = config('app.url');

        return rtrim(is_string($base) ? $base : '', '/')."/admin/rapports/{$year}/{$month}";
    }

    /**
     * « 1re », « 2e », « 3e » (typographie du cahier).
     */
    private static function ordinal(int $number): string
    {
        return $number === 1 ? '1re' : "{$number}e";
    }
}
