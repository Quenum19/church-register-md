<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;

/**
 * Détail d'un rapport (contrat §4) : ligne de la liste +
 * visitors: [{ id, full_name, phone, visit_number, visit_date }] (triés par date)
 * et conversions: [{ id, full_name, converted_at }]. `id` = identifiant du visiteur.
 */
class ReportDetailResource extends ReportSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $report = $this->report();

        return [
            ...parent::toArray($request),
            'visitors' => array_map(static fn (array $visitor): array => [
                'id' => $visitor['id'],
                'full_name' => $visitor['full_name'],
                'phone' => $visitor['phone'],
                'visit_number' => $visitor['visit_number'],
                'visit_date' => $visitor['visit_date']->toDateString(),
            ], $report->visitors),
            'conversions' => array_map(static fn (array $conversion): array => [
                'id' => $conversion['id'],
                'full_name' => $conversion['full_name'],
                'converted_at' => $conversion['converted_at']->toIso8601String(),
            ], $report->conversions),
        ];
    }
}
