<?php

namespace App\Exports;

use App\Enums\VisitorStatus;
use App\Exceptions\ApiException;
use App\Models\Family;
use App\Queries\VisitorListQuery;
use App\Services\SettingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Http\Response;

/**
 * Export PDF (A4 paysage) : vue Blade `exports.visitors` rendue par dompdf.
 *
 * - Toutes les données passent par `{{ }}` (échappement HTML) : jamais `{!! !!}`.
 * - dompdf sans PHP, sans JavaScript, sans ressource distante.
 * - Au-delà de MAX_ROWS lignes (1 000) : 422 `too_many_rows`, avec un message invitant à utiliser
 *   l'export CSV ou Excel (dompdf est trop lent et gourmand au-delà sur un hébergement mutualisé).
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

    public function __construct(
        private readonly VisitorExport $export,
        private readonly SettingsService $settings,
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

        return Pdf::setOption([
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isRemoteEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
        ])
            ->loadHTML($this->html($query, $count))
            ->setPaper('a4', 'landscape')
            ->download($filename)
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * HTML du document (exposé pour les tests d'échappement).
     */
    public function html(VisitorListQuery $query, int $count): string
    {
        $timezone = config('app.timezone');

        return view(self::VIEW, [
            'churchName' => $this->settings->churchName(),
            'generatedAt' => CarbonImmutable::now(is_string($timezone) ? $timezone : 'Africa/Abidjan'),
            'filters' => $this->describeFilters($query),
            'count' => $count,
            'headings' => VisitorExport::HEADINGS,
            'numericColumn' => VisitorExport::VISIT_COUNT_COLUMN,
            'tables' => $this->tables($this->export->rows($query)),
        ])->render();
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

    /**
     * Filtres lisibles affichés en tête du document.
     *
     * @return list<string>
     */
    private function describeFilters(VisitorListQuery $query): array
    {
        $filters = [];

        if ($query->search !== null) {
            $filters[] = "Recherche : « {$query->search} »";
        }

        if ($query->status === VisitorListQuery::STATUS_NON_MEMBER) {
            $filters[] = 'Statut : tous sauf membres';
        } elseif ($query->status !== null) {
            $filters[] = 'Statut : '.(VisitorStatus::tryFrom($query->status)?->label() ?? $query->status);
        }

        if ($query->familyId !== null) {
            $name = Family::query()->whereKey($query->familyId)->value('name');
            $filters[] = 'Famille d\'accueil : '.(is_string($name) ? $name : '#'.$query->familyId);
        }

        if ($query->from !== null) {
            $filters[] = '1re visite à partir du '.CarbonImmutable::parse($query->from)->format('d/m/Y');
        }

        if ($query->to !== null) {
            $filters[] = '1re visite jusqu\'au '.CarbonImmutable::parse($query->to)->format('d/m/Y');
        }

        return $filters;
    }
}
