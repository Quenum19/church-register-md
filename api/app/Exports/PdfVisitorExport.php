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
     * Largeur des 12 colonnes en % de la largeur utile (A4 paysage, marges de 10 mm : 277 mm),
     * dans l'ordre de VisitorExport::HEADINGS ; total = 100. Calibrées à 8 pt pour que
     * « +225 07 00 00 0001 », « 02/08/2026 » et les intitulés d'en-tête tiennent sans
     * chevauchement ni césure disgracieuse.
     *
     * @var list<float>
     */
    public const COLUMN_WIDTHS = [12.5, 11.5, 11.5, 7.0, 8.5, 6.5, 5.5, 7.0, 7.0, 7.5, 9.0, 6.5];

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
            'headings' => VisitorExport::HEADINGS,
            'widths' => self::COLUMN_WIDTHS,
            'numericColumn' => VisitorExport::VISIT_COUNT_COLUMN,
            'tables' => $this->tables($this->export->rows($query)),
        ])->render();
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
     * @param  iterable<int, list<int|string>>  $rows
     * @return Generator<int, list<list<int|string>>>
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
