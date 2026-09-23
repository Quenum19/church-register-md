<?php

namespace App\Http\Resources\Reports;

use App\Services\Reports\MonthlyReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ligne de la liste des rapports (contrat §4) :
 * { year, month, family: {id,name}|null, counts: {v1,v2,v3,total,conversions}, dispatch: {sent_at,recipients}|null }.
 */
class ReportSummaryResource extends JsonResource
{
    public function __construct(MonthlyReport $report)
    {
        parent::__construct($report);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $report = $this->report();

        return [
            'year' => $report->year,
            'month' => $report->month,
            'family' => $report->family !== null
                ? ['id' => $report->family->id, 'name' => $report->family->name]
                : null,
            'counts' => $report->counts,
            'dispatch' => $report->dispatch !== null
                ? (new ReportDispatchResource($report->dispatch))->toArray($request)
                : null,
        ];
    }

    protected function report(): MonthlyReport
    {
        /** @var MonthlyReport */
        return $this->resource;
    }
}
