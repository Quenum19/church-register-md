<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('families', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 50)->unique();
            // Ordre de rotation (1 = Puissance … 7 = Louange).
            $table->unsignedTinyInteger('position');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('family_rotations', function (Blueprint $table): void {
            $table->id();
            // Une famille qui a déjà servi ne peut pas être supprimée (historique des rapports).
            $table->foreignId('family_id')->constrained('families')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->timestamps();

            $table->unique(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_rotations');
        Schema::dropIfExists('families');
    }
};
