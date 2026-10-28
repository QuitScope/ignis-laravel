<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vital sign course (legacy intra_edivi_vitalparameter_einzelwerte): one row per measured value, never
 * overwritten, soft-deleted with who/when. A number goes to `value`, 'ng'/'nm' and remarks to `value_text` (never 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enotf_vitals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enotf_protocol_id')->constrained('enotf_protocols')->cascadeOnDelete();
            $table->string('parameter', 50);
            $table->decimal('value', 10, 2)->nullable();
            $table->string('value_text', 50)->nullable();
            $table->string('unit', 20)->nullable();
            $table->dateTime('measured_at');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->dateTime('deleted_at')->nullable();
            $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('legacy')->nullable();
            $table->timestamps();
            $table->index(['enotf_protocol_id', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enotf_vitals');
    }
};
