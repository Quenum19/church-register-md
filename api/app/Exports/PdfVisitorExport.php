<?php

namespace App\Exports;

use App\Exceptions\ApiException;
use App\Queries\VisitorListQuery;
use App\Services\SettingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfWrapper;
use Carbon\CarbonImmutable;
use Dompdf\Canvas;
use Dompdf\FontMetrics;
use Generator;
use Illuminate\Http\Response;

/**
 * Export PDF (A4 paysage) : vue Blade `exports.visitors` rendue par dompdf.
 *
 * - Toutes les données passent par `{{ }}` (échappement HTML) : jamais `{!! !!}`.
 * - dompdf sans PHP, sans JavaScript, sans ressource distante : le logo est intégré en data URI
 *   base64 calculé côté PHP (ExportLogo), jamais chargé par une URL.
 * - Au-delà de MAX_ROWS lignes (1 000) : 422 `too_many_rows`, avec un message invitant à utiliser
 *   l'export CSV ou Excel (dompdf est trop lent et gourmand au-delà sur un hébergement mutualisé).
 *
 * Le pied de page est estampillé sur le canevas (footer()) et non écrit en CSS : dompdf
 * n'implémente PAS le compteur `counter(pages)` (il vaut toujours 0 ; seul `counter(page)`
 * fonctionne). `page_script()` est le mécanisme natif de dompdf pour la pagination : il inscrit
 * le numéro ET le total sur chaque page, en une seule passe de rendu. Il reçoit ici une closure
 * (jamais une chaîne évaluée), `isPhpEnabled = false` reste donc vrai pour le document.
 */
class PdfVisitorExport
{
    /** Seuil unique de l'export PDF (contrat d'API §4). */
    public const MAX_ROWS = 1000;

    public const VIEW = 'exports.visitors';

    /** Lignes par tableau HTML (voir tables()). */
    public const ROWS_PER_TABLE = 50;

    /** Durée maximale accordée au rendu (dompdf est lent sur les gros tableaux). */
    public const TIME_LIMIT_SECONDS = 300;

    /** Titre du document, sous l'en-tête. */
    public const TITLE = 'Liste des visiteurs';

    /**
     * Colonnes du document : intitulé, largeur en % de la largeur utile (A4 paysage, marges de
     * 10 mm : 277 mm) et alignement. Total = 100.
     *
     * Le PDF est un document de lecture, pas un tableur : il ne reprend PAS les treize colonnes
     * du CSV. Les treize champs restent lisibles, mais hiérarchisés — l'origine sous le nom, le
     * WhatsApp sous le téléphone, la famille d'accueil sous le statut — ce qui laisse à chaque
     * colonne la place de respirer. Numéros et dates ne se coupent plus en deux lignes.
     *
     * @var list<array{label: string, width: float, align: string}>
     */
    public const COLUMNS = [
        ['label' => 'Visiteur', 'width' => 21.0, 'align' => 'left'],
        ['label' => 'Téléphone', 'width' => 17.5, 'align' => 'left'],
        ['label' => 'Commune / Quartier', 'width' => 15.0, 'align' => 'left'],
        ['label' => 'Statut', 'width' => 15.5, 'align' => 'left'],
        ['label' => 'Visites', 'width' => 6.0, 'align' => 'right'],
        ['label' => 'Première visite', 'width' => 12.5, 'align' => 'left'],
        ['label' => 'Dernière visite', 'width' => 12.5, 'align' => 'left'],
    ];

    /** Marge gauche et droite du pied de page, en points (10 mm, comme @page). */
    private const MARGIN_X = 28.35;

    /** Distance entre le bas de la page et le filet du pied de page, en points. */
    private const FOOTER_BOTTOM = 29.3;

    private const FOOTER_FONT_SIZE = 7.0;

    public function __construct(
        private readonly VisitorExport $export,
        private readonly SettingsService $settings,
        private readonly ExportLogo $logo,
    ) {}

    /**
     * @throws ApiException 422 too_many_rows
     */
    public function ensureWithinLimit(int $count): void
    {
        if ($count > self::MAX_ROWS) {
            $max = number_format(self::MAX_ROWS, 0, ',', "\u{202F}");
            $found = number_format($count, 0, ',', "\u{202F}");

            throw new ApiException(
                "L'export PDF est limité à {$max} lignes ({$found} visiteurs correspondent aux filtres). "
                .'Utilisez l\'export CSV ou Excel, ou affinez les filtres.',
                'too_many_rows',
                422,
            );
        }
    }

    public function download(VisitorListQuery $query, int $count, string $filename): Response
    {
        $this->ensureWithinLimit($count);

        // Rendu dompdf long pour plusieurs milliers de lignes : on relève la limite de durée de la
        // requête HTTP (sans effet si l'hébergeur l'interdit ; jamais en console ni en test).
        if (! app()->runningInConsole() && function_exists('set_time_limit')) {
            @set_time_limit(self::TIME_LIMIT_SECONDS);
        }

        /** @var PdfWrapper $pdf */
        $pdf = Pdf::setOption([
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isRemoteEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
            // Sans sous-ensemble, dompdf embarque les 1,4 Mo des DejaVu Sans normale ET grasse
            // dans CHAQUE export (≈ 940 Ko de PDF pour 50 lignes). Avec, le même document pèse
            // ≈ 110 Ko, texte et accents identiques, et se rend légèrement plus vite.
            'isFontSubsettingEnabled' => true,
        ])
            ->loadHTML($this->html($query, $count))
            ->setPaper('a4', 'landscape');

        // Les pages doivent exister avant d'y estampiller « Page X / Y » : on rend explicitement,
        // puis download() réutilise le document déjà rendu (aucune seconde passe de mise en page).
        $pdf->render();
        $this->footer($pdf->getDomPDF()->getCanvas());

        return $pdf->download($filename)->header('Cache-Control', 'no-store, private');
    }

    /**
     * HTML du document (exposé pour les tests d'échappement et de mise en page).
     */
    public function html(VisitorListQuery $query, int $count): string
    {
        $timezone = config('app.timezone');

        return view(self::VIEW, [
            'churchName' => $this->settings->churchName(),
            'subtitle' => VisitorExport::SUBTITLE,
            'title' => self::TITLE,
            'logo' => $this->logo->dataUri(),
            'generatedAt' => CarbonImmutable::now(is_string($timezone) ? $timezone : 'Africa/Abidjan'),
            'filters' => $this->export->describeFilters($query),
            'count' => $count,
            'columns' => self::COLUMNS,
            'tables' => $this->tables($this->lines($query)),
        ])->render();
    }

    /**
     * Lignes telles que la vue les imprime : les champs secondaires (origine, WhatsApp, famille
     * d'accueil) sont déjà rédigés ici, pour que la vue n'ait plus qu'à les afficher.
     *
     * @return Generator<int, array{name: string, origin: string, phone: string, whatsapp: string, commune: string, quartier: string, status: string, family: string, visits: int, first: string, last: string}>
     */
    private function lines(VisitorListQuery $query): Generator
    {
        foreach ($this->export->records($query) as $record) {
            yield [
                'name' => $record['name'],
                'origin' => self::origin($record),
                'phone' => $record['phone'],
                // Un WhatsApp identique au téléphone n'apprend rien : il n'est imprimé que
                // lorsqu'il s'agit d'un second numéro.
                'whatsapp' => $record['whatsapp'] !== $record['phone'] ? $record['whatsapp'] : '',
                'commune' => $record['commune'],
                'quartier' => $record['quartier'],
                'status' => $record['status'],
                'family' => $record['families'],
                'visits' => $record['visits'],
                'first' => $record['first'],
                'last' => $record['last'],
            ];
        }
    }

    /**
     * Origine de la personne, en une ligne sous son nom : « Culte Spécial · Invité(e) par Edson »,
     * ou la source déclarée quand personne ne l'a invitée.
     *
     * @param  array{source: string, invitedBy: string, event: string}  $record
     */
    private static function origin(array $record): string
    {
        $how = $record['invitedBy'] !== ''
            ? 'Invité(e) par '.$record['invitedBy']
            : $record['source'];

        return implode(' · ', array_filter([$record['event'], $how], static fn (string $part): bool => $part !== ''));
    }

    /**
     * Pied de page répété sur chaque page : filet or, nom de l'église à gauche, mention de
     * confidentialité au centre, « Page X / Y » à droite.
     */
    private function footer(Canvas $canvas): void
    {
        $church = $this->settings->churchName();
        $notice = VisitorExport::CONFIDENTIALITY;
        $size = self::FOOTER_FONT_SIZE;
        $gold = self::rgb('C9A227');
        $grey = self::rgb('4B5563');

        $canvas->page_script(static function (int $page, int $pages, Canvas $canvas, FontMetrics $metrics) use ($church, $notice, $size, $gold, $grey): void {
            $font = $metrics->getFont('DejaVu Sans', 'normal');
            $left = self::MARGIN_X;
            $right = $canvas->get_width() - self::MARGIN_X;
            $ruleY = $canvas->get_height() - self::FOOTER_BOTTOM;
            $textY = $ruleY + 4.0;

            $canvas->line($left, $ruleY, $right, $ruleY, $gold, 0.5);
            $canvas->text($left, $textY, $church, $font, $size, $grey);

            $centre = ($left + $right - $metrics->getTextWidth($notice, $font, $size)) / 2;
            $canvas->text($centre, $textY, $notice, $font, $size, $grey);

            $pagination = "Page {$page} / {$pages}";
            $canvas->text($right - $metrics->getTextWidth($pagination, $font, $size), $textY, $pagination, $font, $size, $grey);
        });
    }

    /**
     * « C9A227 » => [0.79, 0.64, 0.15] : le canevas dompdf attend des composantes de 0 à 1.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private static function rgb(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 0, 2)) / 255,
            (int) hexdec(substr($hex, 2, 2)) / 255,
            (int) hexdec(substr($hex, 4, 2)) / 255,
        ];
    }

    /**
     * Regroupe les lignes en tableaux de ROWS_PER_TABLE lignes (mise en page dompdf bien plus
     * rapide qu'un tableau unique, qui est re-découpé à chaque saut de page).
     *
     * @template TRow of array<string, int|string>
     *
     * @param  iterable<int, TRow>  $rows
     * @return Generator<int, list<TRow>>
     */
    private function tables(iterable $rows): Generator
    {
        $table = [];

        foreach ($rows as $row) {
            $table[] = $row;

            if (count($table) === self::ROWS_PER_TABLE) {
                yield $table;
                $table = [];
            }
        }

        if ($table !== []) {
            yield $table;
        }
    }
}
