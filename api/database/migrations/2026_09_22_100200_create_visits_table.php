<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visitor_id')->constrained('visitors')->cascadeOnDelete();
            $table->unsignedTinyInteger('visit_number');
            // Date du jour dans le fuseau APP_TIMEZONE (Africa/Abidjan).
            $table->date('visit_date');
            // NULL si aucune rotation n'était définie pour le mois (alerte journalisée).
            $table->foreignId('family_id')->nullable()->constrained('families')->nullOnDelete();
            // Réponses de l'étape (objet vide pour la visite 1).
            $table->json('answers')->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->timestamp('created_at')->nullable();

            // Une visite par jour et par visiteur ; un seul enregistrement par numéro de visite.
            $table->unique(['visitor_id', 'visit_date']);
            $table->unique(['visitor_id', 'visit_number']);
            $table->index(['family_id', 'visit_date']);
        });

        // Garde-fou en base : 3 visites au maximum (MariaDB applique les contraintes CHECK).
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE visits ADD CONSTRAINT visits_visit_number_check CHECK (visit_number BETWEEN 1 AND 3)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
