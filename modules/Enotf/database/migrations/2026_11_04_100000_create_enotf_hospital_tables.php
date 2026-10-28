<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enotf_hospital_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poi_id')->constrained('pois')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort_order')->default(999);
            $table->enum('status', ['not_staffed', 'available', 'partially_available', 'full'])->default('not_staffed');
            $table->string('note', 255)->nullable();
            $table->dateTime('status_updated_at')->nullable();
            $table->string('status_updated_by', 100)->nullable();
            $table->dateTime('created_at');
            $table->unique(['poi_id', 'name']);
        });

        // One access code per hospital, only as hash (legacy kept plain text); rotating replaces the hash and so signs out
        // every clinic device of the old code (the portal session keeps a fingerprint of the hash).
        Schema::create('enotf_hospital_access_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poi_id')->unique()->constrained('pois')->cascadeOnDelete();
            $table->string('code_hash', 255);
            $table->dateTime('rotated_at');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('last_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enotf_hospital_access_codes');
        Schema::dropIfExists('enotf_hospital_departments');
    }
};
