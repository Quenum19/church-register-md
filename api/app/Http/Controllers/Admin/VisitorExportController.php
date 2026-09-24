<?php

namespace App\Http\Controllers\Admin;

use App\Exports\CsvVisitorExport;
use App\Exports\PdfVisitorExport;
use App\Exports\VisitorExport;
use App\Exports\XlsxVisitorExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VisitorExportRequest;
use App\Queries\VisitorListQuery;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports des visiteurs (visitors.export), mêmes filtres que la liste, générés côté serveur
 * sans plafond silencieux. Chaque export est journalisé (`export.csv|xlsx|pdf`) avec les filtres
 * actifs (sans le texte recherché, qui peut être un nom ou un numéro) et le nombre de lignes.
 */
class VisitorExportController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly VisitorExport $export,
    ) {}

    /**
     * GET /api/admin/exports/visitors.csv
     */
    public function csv(VisitorExportRequest $request, CsvVisitorExport $csv): StreamedResponse
    {
        $query = $request->listQuery();
        $this->log('csv', $query, $this->export->count($query));

        return $csv->download($query, $this->filename('csv'));
    }

    /**
     * GET /api/admin/exports/visitors.xlsx
     */
    public function xlsx(VisitorExportRequest $request, XlsxVisitorExport $xlsx): StreamedResponse
    {
        $query = $request->listQuery();
        $count = $this->export->count($query);
        $this->log('xlsx', $query, $count);

        return $xlsx->download($query, $count, $this->filename('xlsx'));
    }

    /**
     * GET /api/admin/exports/visitors.pdf — 422 `too_many_rows` au-delà de PdfVisitorExport::MAX_ROWS.
     */
    public function pdf(VisitorExportRequest $request, PdfVisitorExport $pdf): Response
    {
        $query = $request->listQuery();
        $count = $this->export->count($query);
        $pdf->ensureWithinLimit($count);
        $this->log('pdf', $query, $count);

        return $pdf->download($query, $count, $this->filename('pdf'));
    }

    private function log(string $format, VisitorListQuery $query, int $rows): void
    {
        $this->audit->log("export.{$format}", null, [
            'filters' => $query->auditFilters(),
            'rows' => $rows,
        ]);
    }

    /**
     * Nom de fichier daté (fuseau applicatif) : visiteurs-2026-09-22-1030.csv.
     */
    private function filename(string $extension): string
    {
        $timezone = config('app.timezone');
        $now = CarbonImmutable::now(is_string($timezone) ? $timezone : 'Africa/Abidjan');

        return 'visiteurs-'.$now->format('Y-m-d-Hi').'.'.$extension;
    }
}
