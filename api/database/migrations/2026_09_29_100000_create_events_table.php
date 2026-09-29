<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Événements (culte spécial, évangélisation) : chaque événement a un lien public dédié
 * `/e/{slug}` et mémorise, sur la visite enregistrée depuis ce lien, son origine.
 *
 * MIGRATION REJOUABLE ET SÛRE SUR LA BASE DE PRODUCTION (qui contient déjà des visites) :
 * - la table et la colonne ne sont créées que si elles n'existent pas déjà ;
 * - `visits.event_id` est NULLABLE, sans valeur par défaut à recalculer : les visites déjà
 *   enregistrées restent inchangées (aucune réécriture de ligne, aucun verrou long) ;
 * - suppression d'un événement => `ON DELETE SET NULL` : une visite n'est jamais perdue.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('events')) {
            Schema::create('events', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                // Segment du lien public /e/{slug} : ^[a-z0-9]+(-[a-z0-9]+)*$.
                $table->string('slug', 60)->unique();
                // NULL : événement sans date arrêtée (le lien reste utilisable).
                $table->date('event_date')->nullable();
                $table->boolean('active')->default(true);
                // NULL si le compte créateur a été supprimé.
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                // Tri de la liste admin : date décroissante, puis nom.
                $table->index(['event_date', 'name']);
            });
        }

        if (! Schema::hasColumn('visits', 'event_id')) {
            Schema::table('visits', function (Blueprint $table): void {
                $table->foreignId('event_id')->nullable()->after('family_id');
                // Index déclaré AVANT la clé étrangère : MariaDB le réutilise au lieu d'en créer
                // un second (le filtre `event_id` de la liste et des exports s'appuie dessus).
                $table->index('event_id');
                $table->foreign('event_id')->references('id')->on('events')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('visits', 'event_id')) {
            Schema::table('visits', function (Blueprint $table): void {
                $table->dropForeign(['event_id']);
                $table->dropIndex(['event_id']);
                $table->dropColumn('event_id');
            });
        }

        Schema::dropIfExists('events');
    }
};
