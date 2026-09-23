<?php

namespace Database\Seeders;

use App\Models\Family;
use App\Models\FamilyRotation;
use Illuminate\Database\Seeder;

/**
 * Les 7 familles d'accueil (ordre de rotation) et les rotations de 2025-11 à 2036-12.
 *
 * Utilisable en production et relançable (`php artisan db:seed --force`) : sans Faker,
 * firstOrCreate uniquement ; une famille ou une rotation existante (éventuellement modifiée
 * par un super_admin) n'est jamais écrasée.
 */
class FamilySeeder extends Seeder
{
    /** @var list<string> Ordre officiel de rotation. */
    public const FAMILIES = ['Puissance', 'Richesse', 'Sagesse', 'Force', 'Honneur', 'Gloire', 'Louange'];

    /** Ancre historique : novembre 2025 = Puissance. */
    public const START_YEAR = 2025;

    public const START_MONTH = 11;

    public const END_YEAR = 2036;

    public const END_MONTH = 12;

    public function run(): void
    {
        $ids = [];

        foreach (self::FAMILIES as $index => $name) {
            $ids[] = Family::query()
                ->firstOrCreate(['name' => $name], ['position' => $index + 1, 'active' => true])
                ->id;
        }

        $offset = 0;

        for ($year = self::START_YEAR; $year <= self::END_YEAR; $year++) {
            $firstMonth = $year === self::START_YEAR ? self::START_MONTH : 1;
            $lastMonth = $year === self::END_YEAR ? self::END_MONTH : 12;

            for ($month = $firstMonth; $month <= $lastMonth; $month++) {
                FamilyRotation::query()->firstOrCreate(
                    ['year' => $year, 'month' => $month],
                    ['family_id' => $ids[$offset % count($ids)]],
                );
                $offset++;
            }
        }
    }
}
