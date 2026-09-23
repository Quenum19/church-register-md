<?php

namespace App\Queries;

use App\Enums\VisitorStatus;
use App\Models\Family;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\FamilyRotationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Statistiques du tableau de bord (GET /api/admin/stats, contrat d'API §4), mises en cache 5 min.
 *
 * Uniquement des requêtes agrégées (COUNT / GROUP BY) : la table des visiteurs n'est jamais
 * chargée en mémoire. Les dates sont calculées dans le fuseau applicatif (Africa/Abidjan).
 */
class DashboardStatsQuery
{
    public const CACHE_KEY = 'admin:stats';

    public const CACHE_TTL_SECONDS = 300;

    /** Nombre de mois de `monthly_visits` (mois courant inclus). */
    public const MONTHS = 12;

    public function __construct(
        private readonly FamilyRotationService $rotations,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        /** @var array<string, mixed> */
        return $this->cache->remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, fn (): array => $this->compute());
    }

    /**
     * Invalide le cache (conversion, suppression, modification de rotation…).
     */
    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    public function compute(): array
    {
        $now = CarbonImmutable::now($this->timezone());
        $byStatus = $this->byStatus();

        return [
            'total_visitors' => array_sum($byStatus),
            'new_today' => Visitor::query()
                ->where('created_at', '>=', $now->startOfDay())
                ->where('created_at', '<', $now->startOfDay()->addDay())
                ->count(),
            'visits_this_month' => Visit::query()
                ->whereBetween('visit_date', [$now->startOfMonth()->toDateString(), $now->endOfMonth()->toDateString()])
                ->count(),
            'by_status' => $this->statusCounts($byStatus),
            'current_family' => $this->familyRef($this->rotations->current()),
            'next_family' => $this->familyRef($this->rotations->next()),
            'visits_by_family' => $this->visitsByFamily($now),
            'monthly_visits' => $this->monthlyVisits($now),
        ];
    }

    /**
     * Nombre de visiteurs par valeur de statut enregistrée.
     *
     * @return array<string, int>
     */
    private function byStatus(): array
    {
        $counts = [];

        $rows = Visitor::query()->toBase()
            ->select('status')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $counts[(string) $row->status] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * Les 4 statuts du contrat, toujours présents (0 par défaut).
     *
     * @param  array<string, int>  $byStatus
     * @return array<string, int>
     */
    private function statusCounts(array $byStatus): array
    {
        $counts = [];

        foreach (VisitorStatus::values() as $status) {
            $counts[$status] = $byStatus[$status] ?? 0;
        }

        return $counts;
    }

    /**
     * Visites de l'année civile en cours par famille d'accueil et par numéro de visite.
     * Toutes les familles actives figurent (à 0 si besoin), plus les familles inactives
     * ayant accueilli des visites cette année ; ordre de rotation.
     *
     * @return list<array{family: array{id: int, name: string}, v1: int, v2: int, v3: int}>
     */
    private function visitsByFamily(CarbonImmutable $now): array
    {
        $counts = [];

        $rows = Visit::query()->toBase()
            ->whereBetween('visit_date', [$now->startOfYear()->toDateString(), $now->endOfYear()->toDateString()])
            ->whereNotNull('family_id')
            ->select('family_id', 'visit_number')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('family_id', 'visit_number')
            ->get();

        foreach ($rows as $row) {
            $counts[(int) $row->family_id]['v'.(int) $row->visit_number] = (int) $row->aggregate;
        }

        $result = [];

        foreach (Family::query()->ordered()->get(['id', 'name', 'active', 'position']) as $family) {
            if (! $family->active && ! isset($counts[$family->id])) {
                continue;
            }

            $result[] = [
                'family' => ['id' => $family->id, 'name' => $family->name],
                'v1' => $counts[$family->id]['v1'] ?? 0,
                'v2' => $counts[$family->id]['v2'] ?? 0,
                'v3' => $counts[$family->id]['v3'] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Visites des 12 derniers mois (mois courant inclus), du plus ancien au plus récent ;
     * un mois sans visite vaut 0.
     *
     * @return list<array{year: int, month: int, count: int}>
     */
    private function monthlyVisits(CarbonImmutable $now): array
    {
        $first = $now->startOfMonth()->subMonthsNoOverflow(self::MONTHS - 1);
        $counts = [];

        $rows = Visit::query()->toBase()
            ->whereBetween('visit_date', [$first->toDateString(), $now->endOfMonth()->toDateString()])
            ->selectRaw('YEAR(visit_date) AS y, MONTH(visit_date) AS m, COUNT(*) AS aggregate')
            ->groupByRaw('YEAR(visit_date), MONTH(visit_date)')
            ->get();

        foreach ($rows as $row) {
            $counts[sprintf('%04d-%02d', (int) $row->y, (int) $row->m)] = (int) $row->aggregate;
        }

        $months = [];

        for ($i = 0; $i < self::MONTHS; $i++) {
            $month = $first->addMonthsNoOverflow($i);
            $months[] = [
                'year' => $month->year,
                'month' => $month->month,
                'count' => $counts[$month->format('Y-m')] ?? 0,
            ];
        }

        return $months;
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function familyRef(?Family $family): ?array
    {
        return $family === null ? null : ['id' => $family->id, 'name' => $family->name];
    }

    private function timezone(): string
    {
        $timezone = config('app.timezone');

        return is_string($timezone) ? $timezone : 'Africa/Abidjan';
    }
}
