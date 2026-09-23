<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('key', 100)->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('report_recipients', function (Blueprint $table): void {
            $table->id();
            // NULL = destinataire global (reçoit les rapports de toutes les familles).
            $table->foreignId('family_id')->nullable()->constrained('families')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('email');
            $table->boolean('active')->default(true);
            $table->timestamps();

            // Attention : dans MariaDB, NULL n'est jamais égal à NULL dans un index unique.
            // Deux destinataires globaux (family_id NULL) avec le même e-mail passent donc cet index :
            // l'unicité (family_id, email) doit AUSSI être validée côté application (Form Request).
            $table->unique(['family_id', 'email']);
        });

        Schema::create('report_dispatches', function (Blueprint $table): void {
            $table->id();
            // Famille de service du mois rapporté.
            $table->foreignId('family_id')->nullable()->constrained('families')->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->timestamp('sent_at');
            // Liste des adresses effectivement destinataires.
            $table->json('recipients');
            // NULL = envoi automatique (tâche planifiée).
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Envoi idempotent : un seul enregistrement par mois rapporté.
            $table->unique(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_dispatches');
        Schema::dropIfExists('report_recipients');
        Schema::dropIfExists('settings');
    }
};
