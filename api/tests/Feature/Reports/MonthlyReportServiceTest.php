<?php

use App\Models\FamilyRotation;
use App\Models\ReportDispatch;
use App\Services\Reports\MonthlyReport;
use App\Services\Reports\MonthlyReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\FamilySeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Reports\Support\ReportData;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-22 10:00', 'Africa/Abidjan'));
    $this->seed(FamilySeeder::class);
    $this->service = app(MonthlyReportService::class);
});

describe('calculs', function (): void {
    beforeEach(function (): void {
        $this->v = ReportData::scenario();
    });

    it('compte exactement les visites et conversions de la famille du mois', function (): void {
        $report = $this->service->forMonth(2026, 8);

        expect($report->family?->name)->toBe('Sagesse')
            ->and($report->counts)->toBe(['v1' => 2, 'v2' => 1, 'v3' => 1, 'total' => 4, 'conversions' => 1])
            ->and($report->inProgress)->toBeFalse();
    });

    it('liste les visites du mois de la famille, triées par date', function (): void {
        $report = $this->service->forMonth(2026, 8);

        $rows = array_map(fn (array $row): array => [
            $row['id'], $row['full_name'], $row['phone'], $row['visit_number'], $row['visit_date']->toDateString(),
        ], $report->visitors);

        expect($rows)->toBe([
            [$this->v['awa']->id, 'Awa Koné', '+2250700000001', 1, '2026-08-02'],
            [$this->v['bakary']->id, 'Bakary Traoré', '+2250700000002', 2, '2026-08-09'],
            [$this->v['chantal']->id, 'Chantal Yao', '+2250700000003', 3, '2026-08-16'],
            [$this->v['gisele']->id, 'Gisèle N\'Guessan', '+2250700000007', 1, '2026-08-31'],
        ]);
    });

    it('exclut les visites d\'une autre famille, sans famille, d\'un autre mois ou d\'une autre année', function (): void {
        $ids = array_column($this->service->forMonth(2026, 8)->visitors, 'id');

        foreach (['didier', 'fatou', 'emile', 'hortense', 'ancien', 'moussa', 'rokia'] as $key) {
            expect($ids)->not->toContain($this->v[$key]->id);
        }
    });

    it('définit les conversions : converti pendant le mois ET 1re visite accueillie par la famille', function (): void {
        $august = $this->service->forMonth(2026, 8);

        expect(array_map(fn (array $c): array => [$c['id'], $c['full_name'], $c['converted_at']->format('Y-m-d H:i')], $august->conversions))
            ->toBe([[$this->v['moussa']->id, 'Moussa Cissé', '2026-08-20 11:00']]);

        // Rokia (1re visite Richesse) n'est comptée ni en août (Sagesse) ni en juillet (pas convertie en juillet).
        // « avant » (31/07 23:59:59) et « après » (01/09 00:00) : bornes du mois exclues.
        expect($this->service->forMonth(2026, 7)->counts['conversions'])->toBe(0)
            ->and($this->service->forMonth(2026, 9)->counts['conversions'])->toBe(0);
    });

    it('compte une conversion quel que soit le mois de la 1re visite', function (): void {
        // Moussa : 1re visite en janvier 2026 (Sagesse), aucune visite en août, converti en août.
        $august = $this->service->forMonth(2026, 8);

        expect(array_column($august->conversions, 'id'))->toContain($this->v['moussa']->id)
            ->and(array_column($august->visitors, 'id'))->not->toContain($this->v['moussa']->id);
    });

    it('produit une liste annuelle cohérente avec le détail de chaque mois', function (): void {
        $reports = $this->service->forYear(2026);

        expect(array_map(fn (MonthlyReport $r): int => $r->month, $reports))->toBe([9, 8, 7, 6, 5, 4, 3, 2, 1]);

        foreach ($reports as $report) {
            expect($report->counts)->toBe($this->service->forMonth(2026, $report->month)->counts, "mois {$report->month}")
                ->and($report->visitors)->toBe([])
                ->and($report->conversions)->toBe([]);
        }

        $byMonth = collect($reports)->keyBy('month');

        expect($byMonth[9]->counts)->toBe(['v1' => 2, 'v2' => 0, 'v3' => 0, 'total' => 2, 'conversions' => 0])
            ->and($byMonth[9]->family?->name)->toBe('Force')
            ->and($byMonth[9]->inProgress)->toBeTrue()
            ->and($byMonth[8]->counts)->toBe(['v1' => 2, 'v2' => 1, 'v3' => 1, 'total' => 4, 'conversions' => 1])
            ->and($byMonth[7]->counts)->toBe(['v1' => 1, 'v2' => 1, 'v3' => 0, 'total' => 2, 'conversions' => 0])
            ->and($byMonth[3]->counts)->toBe(['v1' => 0, 'v2' => 0, 'v3' => 3, 'total' => 3, 'conversions' => 0])
            ->and($byMonth[2]->counts)->toBe(['v1' => 0, 'v2' => 3, 'v3' => 1, 'total' => 4, 'conversions' => 0])
            ->and($byMonth[1]->counts)->toBe(['v1' => 3, 'v2' => 1, 'v3' => 0, 'total' => 4, 'conversions' => 0]);
    });

    it('renvoie les 12 mois d\'une année passée, du plus récent au plus ancien', function (): void {
        $reports = $this->service->forYear(2025);

        expect(array_map(fn (MonthlyReport $r): int => $r->month, $reports))->toBe(range(12, 1))
            ->and($reports[0]->family?->name)->toBe('Richesse')
            ->and($reports[0]->counts['v1'])->toBe(1)
            ->and($reports[1]->family?->name)->toBe('Puissance');
    });

    it('rattache l\'envoi éventuel du mois', function (): void {
        ReportDispatch::factory()->forMonth(2026, 8)->create(['recipients' => ['a@exemple.test']]);

        $byMonth = collect($this->service->forYear(2026))->keyBy('month');

        expect($byMonth[8]->dispatch?->recipients)->toBe(['a@exemple.test'])
            ->and($byMonth[7]->dispatch)->toBeNull()
            ->and($this->service->forMonth(2026, 8)->dispatch?->recipients)->toBe(['a@exemple.test']);
    });

    it('calcule la liste annuelle en un nombre constant de requêtes (sans N+1)', function (): void {
        $countQueries = function (callable $callback): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $callback();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $before = $countQueries(fn () => $this->service->forYear(2026));
        $detailBefore = $countQueries(fn () => $this->service->forMonth(2026, 8));

        foreach (range(1, 5) as $i) {
            $visitor = ReportData::visitor(['2026-01-04', '2026-02-01', "2026-08-0{$i}"]);
            ReportData::convert($visitor, "2026-08-1{$i} 10:00");
        }

        expect($countQueries(fn () => $this->service->forYear(2026)))->toBe($before)
            ->and($before)->toBeLessThanOrEqual(7)
            ->and($countQueries(fn () => $this->service->forMonth(2026, 8)))->toBe($detailBefore)
            ->and($detailBefore)->toBeLessThanOrEqual(7);
    });
});

describe('mois sans rotation', function (): void {
    it('renvoie family = null et des compteurs à 0, même si des visites existent', function (): void {
        ReportData::visitor([['2026-08-10', ReportData::family('Sagesse')]]);
        FamilyRotation::query()->where('year', 2026)->where('month', 8)->delete();

        $report = $this->service->forMonth(2026, 8);

        expect($report->family)->toBeNull()
            ->and($report->counts)->toBe(MonthlyReport::emptyCounts())
            ->and($report->visitors)->toBe([])
            ->and($report->conversions)->toBe([]);

        $summary = collect($this->service->forYear(2026))->firstWhere('month', 8);

        expect($summary->family)->toBeNull()
            ->and($summary->counts)->toBe(MonthlyReport::emptyCounts());
    });
});

describe('années et mois disponibles', function (): void {
    it('se limite à l\'année courante sans aucune visite', function (): void {
        expect($this->service->availableYears())->toBe([2026]);
    });

    it('commence à l\'année de la première visite', function (): void {
        ReportData::visitor(['2024-05-05']);

        expect($this->service->availableYears())->toBe([2024, 2025, 2026]);
    });

    it('n\'admet que les mois écoulés ou en cours des années disponibles', function (int $year, int $month, bool $exists): void {
        ReportData::visitor(['2025-03-02']);

        expect($this->service->exists($year, $month))->toBe($exists);
    })->with([
        'mois en cours' => [2026, 9, true],
        'mois écoulé' => [2026, 1, true],
        'année passée disponible' => [2025, 1, true],
        'mois futur' => [2026, 10, false],
        'année future' => [2027, 1, false],
        'avant la 1re année' => [2024, 12, false],
        'mois 0' => [2026, 0, false],
        'mois 13' => [2026, 13, false],
    ]);

    it('ne renvoie aucun rapport pour une année future', function (): void {
        expect($this->service->forYear(2027))->toBe([]);
    });
});
