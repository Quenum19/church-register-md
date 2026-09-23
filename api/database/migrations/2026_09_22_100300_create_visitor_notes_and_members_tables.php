<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_notes', function (Blueprint $table): void {
            $table->id();
            // Notes supprimées avec le visiteur.
            $table->foreignId('visitor_id')->constrained('visitors')->cascadeOnDelete();
            // Auteur conservé à NULL si le compte est supprimé (la note reste).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visitor_id')->unique()->constrained('visitors')->cascadeOnDelete();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('converted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
        Schema::dropIfExists('visitor_notes');
    }
};
