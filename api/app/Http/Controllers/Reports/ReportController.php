<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ListReportsRequest;
use App\Http\Requests\Reports\SendReportRequest;
use App\Http\Resources\Reports\ReportDetailResource;
use App\Http\Resources\Reports\ReportDispatchResource;
use App\Http\Resources\Reports\ReportSummaryResource;
use App\Models\User;
use App\Services\Reports\MonthlyReportService;
use App\Services\Reports\ReportDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Rapports mensuels (contrat d'API §4). Un mois futur, invalide ou antérieur aux années
 * disponibles n'a pas de rapport : 404 `not_found` (consultation comme envoi).
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly MonthlyReportService $reports,
        private readonly ReportDispatcher $dispatcher,
    ) {}

    /**
     * GET /api/admin/reports?year=YYYY (visitors.view).
     */
    public function index(ListReportsRequest $request): AnonymousResourceCollection
    {
        return ReportSummaryResource::collection($this->reports->forYear($request->year()))
            ->additional(['meta' => ['available_years' => $this->reports->availableYears()]]);
    }

    /**
     * GET /api/admin/reports/{year}/{month} (visitors.view).
     */
    public function show(int $year, int $month): ReportDetailResource
    {
        $this->ensureExists($year, $month);

        return new ReportDetailResource($this->reports->forMonth($year, $month));
    }

    /**
     * POST /api/admin/reports/{year}/{month}/send (reports.send) — 200 { data: dispatch }.
     */
    public function send(SendReportRequest $request, int $year, int $month): JsonResponse
    {
        $this->ensureExists($year, $month);

        $user = $request->user();
        $dispatch = $this->dispatcher->send($year, $month, $user instanceof User ? $user : null, $request->force());

        // 200 même lors du premier envoi (sinon JsonResource répond 201 pour un modèle créé).
        return (new ReportDispatchResource($dispatch))->response()->setStatusCode(200);
    }

    private function ensureExists(int $year, int $month): void
    {
        if (! $this->reports->exists($year, $month)) {
            throw new NotFoundHttpException;
        }
    }
}
