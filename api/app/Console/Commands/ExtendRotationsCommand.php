<?php

namespace App\Console\Commands;

use App\Services\FamilyRotationService;
use Illuminate\Console\Command;

/**
 * Garantit une rotation des familles pour les N prochains mois (planifiée le 1er du mois).
 */
class ExtendRotationsCommand extends Command
{
    protected $signature = 'rotations:extend
        {--months=24 : Nombre de mois à couvrir après le mois courant}';

    protected $description = 'Complète la rotation mensuelle des familles pour les mois à venir';

    public function handle(FamilyRotationService $rotations): int
    {
        $months = filter_var($this->option('months'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 240]]);

        if ($months === false) {
            $this->components->error("L'option --months doit être un entier entre 0 et 240.");

            return self::FAILURE;
        }

        $created = $rotations->ensureMonthsAhead($months);

        $this->components->info("{$created} rotation(s) créée(s) ; {$months} mois couverts après le mois courant.");

        return self::SUCCESS;
    }
}
