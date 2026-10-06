<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les congrégations de l'Église : l'assemblée à laquelle une personne appartient.
 *
 * À NE PAS CONFONDRE avec les familles d'accueil (`families`), qui organisent le service
 * d'accueil par rotation mensuelle : quelqu'un peut servir dans la famille Force et appartenir
 * à la congrégation Puissance. Les sept familles portent les mêmes noms que sept congrégations,
 * ce sont pourtant deux appartenances distinctes.
 *
 * La liste est semée ICI (et non par un seeder) : le déploiement ne lance que `migrate --force`.
 * Même structure que `families` pour que l'une puisse servir de modèle à l'autre.
 */
return new class extends Migration
{
    /** @var list<string> Ordre d'affichage voulu par l'Église. */
    public const CONGREGATIONS = [
        'Puissance',
        'Richesse',
        'Sagesse',
        'Force',
        'Honneur',
        'Gloire',
        'Louange',
        'Voix de la Destinée',
        'New Creation',
        'New Wine',
        'Jeunesse',
    ];

    public function up(): void
    {
        Schema::create('congregations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique();
            $table->unsignedSmallInteger('position');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $now = now();
        $rows = [];

        foreach (self::CONGREGATIONS as $index => $name) {
            $rows[] = [
                'name' => $name,
                'position' => $index + 1,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('congregations')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('congregations');
    }
};
