<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Données de référence uniquement. AUCUN compte utilisateur n'est créé ici :
 * le premier super_admin se crée avec `php artisan admin:create`.
 * Données de démonstration : `php artisan db:seed --class=DemoSeeder` (hors production).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            FamilySeeder::class,
            SettingsSeeder::class,
        ]);
    }
}
