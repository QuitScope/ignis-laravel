<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Hybrid schema. Instants are UTC dateTime (older MariaDB adds ON UPDATE to a first NOT NULL timestamp). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enotf_protocols', function (Blueprint $table) {
            $table->id();
            $table->string('enr', 64)->unique();
            $table->enum('status', ['open', 'released', 'withdrawn'])->default('open');
            $table->enum('origin', ['dispatch', 'manual', 'unknown'])->default('manual');
            $table->unsignedTinyInteger('qm_status')->default(0);
            $table->dateTime('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('released_by_name', 255)->nullable();
            $table->dateTime('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('billed_at')->nullable();
            $table->string('billing_batch', 26)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('vehicle_transport_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('vehicle_transport_label', 255)->nullable();
            $table->foreignId('vehicle_na_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('vehicle_na_label', 255)->nullable();
            $table->date('mission_date')->nullable();
            $table->string('mission_time', 5)->nullable();
            $table->dateTime('alarmed_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->string('patient_first_name', 255)->nullable();
            $table->string('patient_last_name', 255)->nullable();
            $table->date('patient_birthdate')->nullable();
            $table->unsignedTinyInteger('patient_sex')->nullable();
            $table->string('destination_code', 16)->nullable();
            $table->foreignId('destination_poi_id')->nullable()->constrained('pois')->nullOnDelete();
            $table->json('data')->nullable();
            $table->json('legacy')->nullable();
            $table->enum('patient_sync', ['none', 'pending', 'synced'])->default('none');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['vehicle_transport_id', 'status']);
            $table->index(['vehicle_na_id', 'status']);
            $table->index(['status', 'billed_at', 'created_at']);
            $table->index('qm_status');
            $table->index('patient_sync');
            $table->index('mission_date');
        });

        Schema::create('enotf_protocol_crew', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enotf_protocol_id')->constrained('enotf_protocols')->cascadeOnDelete();
            $table->enum('slot', ['transport', 'na']);
            $table->unsignedTinyInteger('position');
            $table->foreignId('personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->string('name', 255)->nullable();
            $table->string('qualification', 255)->nullable();

            $table->unique(['enotf_protocol_id', 'slot', 'position']);
            $table->index('personnel_id');
        });

        Schema::create('enotf_crews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->string('ended_reason', 32)->nullable();

            $table->index(['vehicle_id', 'ended_at']);
        });

        Schema::create('enotf_crew_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enotf_crew_id')->constrained('enotf_crews')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('joined_at');
            $table->dateTime('left_at')->nullable();

            $table->index(['enotf_crew_id', 'left_at']);
            $table->index(['personnel_id', 'left_at']);
        });

        Schema::create('enotf_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('enotf_quicklinks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enotf_category_id')->constrained('enotf_categories')->restrictOnDelete();
            $table->string('title', 255);
            $table->string('url', 500);
            $table->string('icon', 100)->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('col_width', 20)->default('col-6');
            $table->boolean('active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['enotf_category_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enotf_quicklinks');
        Schema::dropIfExists('enotf_categories');
        Schema::dropIfExists('enotf_crew_members');
        Schema::dropIfExists('enotf_crews');
        Schema::dropIfExists('enotf_protocol_crew');
        Schema::dropIfExists('enotf_protocols');
    }
};
