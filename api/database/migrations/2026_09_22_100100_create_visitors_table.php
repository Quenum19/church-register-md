<?php

use App\Enums\VisitorStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitors', function (Blueprint $table): void {
            $table->id();
            // Numéro normalisé E.164 (App\Services\PhoneNumberService) : clé d'identification.
            $table->string('phone', 20)->unique();
            $table->string('full_name', 100);
            // NULL si vide (jamais de chaîne vide).
            $table->string('whatsapp', 20)->nullable();
            $table->string('commune', 80);
            $table->string('quartier', 80);
            $table->string('source', 30);
            $table->string('source_other', 200)->nullable();
            $table->string('invited_by', 100)->nullable();
            $table->foreignId('inviter_family_id')->nullable()->constrained('families')->nullOnDelete();
            $table->boolean('wants_whatsapp_group')->default(false);
            $table->timestamp('consent_at')->nullable();
            // Écrit uniquement par App\Services\VisitorStatusService.
            $table->string('status', 20)->default(VisitorStatus::Prospect->value)->index();
            $table->timestamps();

            $table->index('created_at');
            $table->fullText(['full_name', 'commune', 'quartier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitors');
    }
};
