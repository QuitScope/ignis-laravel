<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Enotf\Support\MedicationSeeds;

/**
 * Medication catalog (legacy intra_edivi_medikamente, 47 seeds) and the given medication as rows (legacy
 * JSON column intra_edivi.medis): one row per administration, never overwritten, soft-deleted with who/when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enotf_medications', function (Blueprint $table) {
            $table->id();
            $table->string('substance')->unique();
            $table->string('brand')->nullable();
            $table->json('doses')->nullable();
            $table->integer('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('enotf_medications_given', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enotf_protocol_id')->constrained('enotf_protocols')->cascadeOnDelete();
            $table->foreignId('medication_id')->nullable()->constrained('enotf_medications')->nullOnDelete();
            $table->string('name');
            $table->string('dose', 50)->nullable();
            $table->string('unit', 10)->nullable();
            $table->string('route', 20)->nullable();
            $table->dateTime('given_at');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->dateTime('deleted_at')->nullable();
            $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('legacy')->nullable();
            $table->timestamps();
            $table->index(['enotf_protocol_id', 'given_at']);
        });

        MedicationSeeds::seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('enotf_medications_given');
        Schema::dropIfExists('enotf_medications');
    }
};
