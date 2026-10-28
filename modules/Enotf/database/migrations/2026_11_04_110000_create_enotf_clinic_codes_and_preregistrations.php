<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clinic code: read access to exactly one protocol for 1 h. The code is
        // `<id>-<secret>`; only the secret's hash is stored. Expired rows go in enotf:prune.
        Schema::create('enotf_clinic_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enotf_protocol_id')->constrained('enotf_protocols')->cascadeOnDelete();
            $table->string('code_hash', 255);
            $table->dateTime('expires_at')->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('created_at');
        });

        Schema::create('enotf_preregistrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enotf_protocol_id')->constrained('enotf_protocols')->cascadeOnDelete();
            $table->foreignId('poi_id')->constrained('pois')->cascadeOnDelete();
            $table->enum('status', ['open', 'seen', 'arrived', 'cancelled'])->default('open');
            $table->unsignedTinyInteger('priority')->default(0);
            $table->dateTime('eta')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('expires_at');
            $table->index(['poi_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enotf_preregistrations');
        Schema::dropIfExists('enotf_clinic_codes');
    }
};
