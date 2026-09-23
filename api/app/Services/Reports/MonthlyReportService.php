<?php

namespace App\Services\Reports;

use App\Models\Family;
use App\Models\FamilyRotation;
use App\Models\Member;
use App\Models\ReportDispatch;
use App\Models\Visit;
use App\Services\FamilyRotationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Rapports mensuels (contrat d'API §4, « Rapports mensuels ») : un rapport = (année, mois)
 * pour la famille de service de ce mois (`family_rotations`), calculé à la volée.
 *
 * Définitions :
 * - v1 / v2 / v3 : visites de numéro 1 / 2 / 3 accueillies par la famille du mois
 *   (`visits.family_id`) et datées dans le mois ; `total` = v1 + v2 + v3.
 * - conversions : membres convertis pendant le mois dont la 1re visite a été accueillie par la
 *   famille du rapport (la 1re visite peut dater d'un mois antérieur).
 * - Mois sans rotation : `family` = null, compteurs à 0, listes vides.
 *
 * Un rapport « existe » pour tout mois écoulé ou en cours d'une année de availableYears().
 * Toutes les lectures sont agrégées (nombre de requêtes constant, sans N+1).
 */
class MonthlyReportService
{
    public function __construct(private readonly FamilyRotationService $rotations) {}

    /**
     * Années consultables : de l'année de la 1re visite enregistrée (ou l'année courante
     * s'il n'y en a aucune) à l'année courante, dans l'ordre croissant.
     *
     * @return list<int>
     */
    public function availableYears(): array
    {
        $current = $this->today()->year;
        $first = Visit::query()->min('visit_date');
        $firstYear = is_string($first) && $first !== '' ? (int) substr($first, 0, 4) : $current;

        return range(min($firstYear, $current), $current);
    }

    /**
     * Vrai si le rapport (année, mois) peut être consulté : mois valide, année disponible,
     * mois écoulé ou en cours (jamais un mois futur).
     */
    public function exists(int $year, int $month): bool
    {
        if ($month < 1 || $month > 12 || ! in_array($year, $this->availableYears(), true)) {
            return false;
        }

        $today = $this->today();

        return $year < $today->year || $month <= $today->month;
    }

    /**
     * Rapports d'une année (compteurs et envoi, sans les listes) : jusqu'au mois courant inclus
     * pour l'année en cours, les 12 mois pour une année passée ; du plus récent au plus ancien.
     *
     * @return list<MonthlyReport>
     */
    public function forYear(int $year): array
    {
        $today = $this->today();

        if ($year > $today->year) {
            return [];
        }

        $lastMonth = $year === $today->year ? $today->month : 12;
        [$start] = $this->bounds($year, 1);
        [, $end] = $this->bounds($year, $lastMonth);

        /** @var Collection<int, Family> $families mois => famille de service */
        $families = FamilyRotation::query()
            ->with('family')
            ->where('year', $year)
            ->whereBetween('month', [1, $lastMonth])
            ->get()
            ->mapWithKeys(fn (FamilyRotation $rotation): array => [$rotation->month => $rotation->family]);

        $visitCounts = $this->visitCountsByMonth($start, $end);
        $conversionCounts = $this->conversionCountsByMonth($start, $end);

        /** @var Collection<int, ReportDispatch> $dispatches */
        $dispatches = ReportDispatch::query()->where('year', $year)->get()->keyBy('month');

        $reports = [];

        for ($month = $lastMonth; $month >= 1; $month--) {
            $family = $families->get($month);
            $counts = MonthlyReport::emptyCounts();

            if ($family !== null) {
                foreach ([1, 2, 3] as $number) {
                    $counts["v{$number}"] = $visitCounts[$month][$family->id][$number] ?? 0;
                }

                $counts['total'] = $counts['v1'] + $counts['v2'] + $counts['v3'];
                $counts['conversions'] = $conversionCounts[$month][$family->id] ?? 0;
            }

            $reports[] = new MonthlyReport(
                year: $year,
                month: $month,
                family: $family,
                counts: $counts,
                dispatch: $dispatches->get($month),
                inProgress: $this->isInProgress($year, $month),
            );
        }

        return $reports;
    }

    /**
     * Rapport complet d'un mois : compteurs, visites accueillies (triées par date), conversions
     * et envoi éventuel. L'appelant vérifie au préalable exists().
     */
    public function forMonth(int $year, int $month): MonthlyReport
    {
        $family = $this->rotations->familyForMonth($year, $month);
        $dispatch = ReportDispatch::query()->forMonth($year, $month)->first();
        $inProgress = $this->isInProgress($year, $month);

        if ($family === null) {
            return new MonthlyReport($year, $month, null, MonthlyReport::emptyCounts(), $dispatch, $inProgress);
        }

        [$start, $end] = $this->bounds($year, $month);

        $visits = Visit::query()
            ->with('visitor:id,full_name,phone')
            ->where('family_id', $family->id)
            ->where('visit_date', '>=', $start->toDateString())
            ->where('visit_date', '<', $end->toDateString())
            ->orderBy('visit_date')
            ->orderBy('id')
            ->get(['id', 'visitor_id', 'visit_number', 'visit_date']);

        $members = Member::query()
            ->with('visitor:id,full_name')
            ->where('converted_at', '>=', $start)
            ->where('converted_at', '<', $end)
            ->whereExists(fn (QueryBuilder $query) => $this->firstVisitHostedBy($query, $family->id))
            ->orderBy('converted_at')
            ->orderBy('id')
            ->get(['id', 'visitor_id', 'converted_at']);

        $visitors = $visits->map(fn (Visit $visit): array => [
            'id' => $visit->visitor->id,
            'full_name' => $visit->visitor->full_name,
            'phone' => $visit->visitor->phone,
            'visit_number' => $visit->visit_number,
            'visit_date' => $visit->visit_date,
        ])->values()->all();

        $conversions = $members->map(fn (Member $member): array => [
            'id' => $member->visitor->id,
            'full_name' => $member->visitor->full_name,
            'converted_at' => $member->converted_at,
        ])->values()->all();

        $counts = MonthlyReport::emptyCounts();

        foreach ([1, 2, 3] as $number) {
            $counts["v{$number}"] = $visits->where('visit_number', $number)->count();
        }

        $counts['total'] = $counts['v1'] + $counts['v2'] + $counts['v3'];
        $counts['conversions'] = count($conversions);

        return new MonthlyReport($year, $month, $family, $counts, $dispatch, $inProgress, $visitors, $conversions);
    }

    /**
     * Visites par mois, famille et numéro sur la période [$start, $end[ (une seule requête agrégée).
     *
     * @return array<int, array<int, array<int, int>>> mois => family_id => visit_number => nombre
     */
    private function visitCountsByMonth(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = Visit::query()
            ->toBase()
            ->selectRaw('MONTH(visit_date) AS report_month, family_id, visit_number, COUNT(*) AS aggregate')
            ->where('visit_date', '>=', $start->toDateString())
            ->where('visit_date', '<', $end->toDateString())
            ->whereNotNull('family_id')
            ->groupByRaw('MONTH(visit_date), family_id, visit_number')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            /** @var object{report_month: int|string, family_id: int|string, visit_number: int|string, aggregate: int|string} $row */
            $counts[(int) $row->report_month][(int) $row->family_id][(int) $row->visit_number] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * Conversions par mois de conversion et par famille d'accueil de la 1re visite.
     *
     * @return array<int, array<int, int>> mois => family_id (1re visite) => nombre
     */
    private function conversionCountsByMonth(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = Member::query()
            ->toBase()
            ->join('visits as first_visit', function ($join): void {
                $join->on('first_visit.visitor_id', '=', 'members.visitor_id')
                    ->where('first_visit.visit_number', '=', 1);
            })
            ->selectRaw('MONTH(members.converted_at) AS report_month, first_visit.family_id, COUNT(*) AS aggregate')
            ->where('members.converted_at', '>=', $start)
            ->where('members.converted_at', '<', $end)
            ->whereNotNull('first_visit.family_id')
            ->groupByRaw('MONTH(members.converted_at), first_visit.family_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            /** @var object{report_month: int|string, family_id: int|string, aggregate: int|string} $row */
            $counts[(int) $row->report_month][(int) $row->family_id] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * Sous-requête EXISTS : la 1re visite du membre a été accueillie par la famille donnée.
     */
    private function firstVisitHostedBy(QueryBuilder $query, int $familyId): void
    {
        $query->selectRaw('1')
            ->from('visits')
            ->whereColumn('visits.visitor_id', 'members.visitor_id')
            ->where('visits.visit_number', 1)
            ->where('visits.family_id', $familyId);
    }

    /**
     * Bornes du mois dans le fuseau applicatif : [1er jour 00:00, 1er jour du mois suivant 00:00[.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function bounds(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $this->timezone());

        return [$start, $start->addMonthNoOverflow()];
    }

    private function isInProgress(int $year, int $month): bool
    {
        $today = $this->today();

        return $year === $today->year && $month === $today->month;
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    private function timezone(): string
    {
        $timezone = config('app.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Africa/Abidjan';
    }
}
