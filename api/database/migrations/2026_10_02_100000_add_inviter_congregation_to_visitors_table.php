<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Congrégation de la personne qui a invité le visiteur.
 *
 * L'Église compte plusieurs congrégations : savoir d'où vient l'invitant oriente le suivi,
 * là où `inviter_family_id` ne désigne que sa famille d'accueil. Facultatif et libre : aucune
 * liste n'est imposée, le champ accepte le nom tel que la personne le donne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            // NULL si vide (jamais de chaîne vide), comme `invited_by` juste au-dessus.
            $table->string('inviter_congregation', 100)->nullable()->after('invited_by');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->dropColumn('inviter_congregation');
        });
    }
};
