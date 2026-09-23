<?php

namespace Tests\Feature\Admin\Support;

use App\Models\Family;
use App\Models\Member;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\VisitorStatusService;
use Carbon\CarbonImmutable;
use Database\Seeders\FamilySeeder;
use Illuminate\Support\Facades\DB;

/**
 * Données de test de l'API admin « visiteurs ».
 *
 * Date de référence : 22 septembre 2026, 10 h (Africa/Abidjan) — famille de service Force,
 * mois suivant Honneur (ancre : novembre 2025 = Puissance).
 */
final class AdminFixtures
{
    public const NOW = '2026-09-22 10:00:00';

    public const TIMEZONE = 'Africa/Abidjan';

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW, self::TIMEZONE);
    }

    /**
     * Les 7 familles et leurs rotations de 2025-11 à 2027-12 (insertion groupée, plus rapide
     * que le FamilySeeder complet). Renvoie les familles indexées par nom.
     *
     * @return array<string, Family>
     */
    public static function families(): array
    {
        $timestamp = self::now()->toDateTimeString();
        $ids = [];

        foreach (FamilySeeder::FAMILIES as $index => $name) {
            $ids[] = DB::table('families')->insertGetId([
                'name' => $name,
                'position' => $index + 1,
                'active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        $rows = [];
        $offset = 0;

        for ($month = 2025 * 12 + 10; $month <= 2027 * 12 + 11; $month++) {
            $rows[] = [
                'family_id' => $ids[$offset % count($ids)],
                'year' => intdiv($month, 12),
                'month' => $month % 12 + 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            $offset++;
        }

        DB::table('family_rotations')->insert($rows);

        return Family::query()->ordered()->get()->keyBy('name')->all();
    }

    /**
     * Visiteur avec des visites aux dates données (visites n° 1, 2, 3 dans l'ordre), inscrit le jour
     * de sa 1re visite à 10 h ; statut recalculé par VisitorStatusService.
     *
     * @param  list<string>  $dates  dates YYYY-MM-DD
     * @param  array<string, mixed>  $attributes
     */
    public static function visitor(
        array $dates,
        array $attributes = [],
        bool $member = false,
        ?User $convertedBy = null,
        bool $withFamily = true,
    ): Visitor {
        $registered = CarbonImmutable::parse($dates[0] ?? self::NOW, self::TIMEZONE)->setTime(10, 0);

        $visitor = Visitor::factory()->create([
            'created_at' => $registered,
            'updated_at' => $registered,
            ...$attributes,
        ]);

        foreach ($dates as $index => $date) {
            $factory = Visit::factory()->step($index + 1)->for($visitor)->onDate($date);
            ($withFamily ? $factory : $factory->withoutFamily())->create();
        }

        if ($member) {
            Member::query()->create([
                'visitor_id' => $visitor->id,
                'converted_by' => $convertedBy?->id,
                'converted_at' => self::now(),
            ]);
        }

        app(VisitorStatusService::class)->refresh($visitor);

        return $visitor->refresh();
    }

    /**
     * Insère `$count` visiteurs sans visite en une poignée de requêtes (tests de volume).
     */
    public static function bulkVisitors(int $count, string $namePrefix = 'Visiteur'): void
    {
        $timestamp = self::now()->subDay()->toDateTimeString();
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'phone' => '+22507'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'full_name' => sprintf('%s %05d', $namePrefix, $i),
                'commune' => 'Cocody',
                'quartier' => 'Angré',
                'source' => 'passage',
                'status' => 'prospect',
                'wants_whatsapp_group' => false,
                'consent_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            if (count($rows) === 500) {
                DB::table('visitors')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('visitors')->insert($rows);
        }
    }
}
