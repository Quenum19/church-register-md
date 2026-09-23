<?php

namespace App\Services\Reports;

use App\Models\Family;
use App\Models\ReportDispatch;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Rapport mensuel calculé à la volée (voir MonthlyReportService).
 *
 * Les listes `visitors` et `conversions` ne sont remplies que pour le détail d'un mois
 * (MonthlyReportService::forMonth) ; la liste annuelle ne contient que les compteurs.
 */
final readonly class MonthlyReport
{
    /**
     * @param  array{v1: int, v2: int, v3: int, total: int, conversions: int}  $counts
     * @param  list<array{id: int, full_name: string, phone: string, visit_number: int, visit_date: CarbonInterface}>  $visitors
     * @param  list<array{id: int, full_name: string, converted_at: CarbonInterface}>  $conversions
     */
    public function __construct(
        public int $year,
        public int $month,
        public ?Family $family,
        public array $counts,
        public ?ReportDispatch $dispatch,
        public bool $inProgress,
        public array $visitors = [],
        public array $conversions = [],
    ) {}

    /**
     * Compteurs vides (mois sans rotation, mois sans visite).
     *
     * @return array{v1: int, v2: int, v3: int, total: int, conversions: int}
     */
    public static function emptyCounts(): array
    {
        return ['v1' => 0, 'v2' => 0, 'v3' => 0, 'total' => 0, 'conversions' => 0];
    }

    /**
     * Mois en toutes lettres, en français : « septembre 2026 ».
     */
    public function monthLabel(): string
    {
        return CarbonImmutable::create($this->year, $this->month, 1)->locale('fr')->isoFormat('MMMM YYYY');
    }

    /**
     * Nom de la famille de service, ou « non définie » pour un mois sans rotation.
     */
    public function familyName(): string
    {
        return $this->family !== null ? $this->family->name : 'non définie';
    }
}
