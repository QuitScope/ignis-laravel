<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manv_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_number', 50);
            $table->string('location', 255);
            $table->text('reason')->nullable();
            $table->foreignId('lna_personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->string('lna_name', 255)->nullable();
            $table->foreignId('orgl_personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->string('orgl_name', 255)->nullable();
            $table->enum('status', ['active', 'closed', 'archived'])->default('active');
            $table->dateTime('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('started_at');
        });

        Schema::create('manv_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manv_incident_id')->constrained('manv_incidents')->cascadeOnDelete();
            $table->enum('type', ['vehicle', 'personnel', 'material']);
            $table->string('label', 255);
            $table->string('callsign', 255)->nullable();
            $table->string('vehicle_type', 255)->nullable();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('location', 255)->nullable();
            $table->enum('status', ['available', 'deployed', 'unavailable'])->default('available');
            $table->string('crew', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('departed_at')->nullable();
            $table->timestamps();

            $table->unique(['manv_incident_id', 'label']);
        });

        Schema::create('manv_patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manv_incident_id')->constrained('manv_incidents')->cascadeOnDelete();
            $table->string('number', 50);
            $table->string('last_name', 255)->nullable();
            $table->string('first_name', 255)->nullable();
            $table->date('birth_date')->nullable();
            $table->enum('sex', ['m', 'f', 'd', 'unknown'])->default('unknown');
            $table->enum('triage', ['SK1', 'SK2', 'SK3', 'SK4', 'SK5', 'SK6'])->nullable();
            $table->timestamp('triaged_at')->nullable();
            $table->foreignId('triaged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('manv_resource_id')->nullable()->constrained('manv_resources')->nullOnDelete();
            $table->string('vehicle_type', 255)->nullable();
            $table->string('callsign', 255)->nullable();
            $table->string('vehicle_location', 255)->nullable();
            $table->string('destination', 255)->nullable();
            $table->foreignId('destination_poi_id')->nullable()->constrained('pois')->nullOnDelete();
            $table->timestamp('departed_at')->nullable();
            $table->text('injuries')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['manv_incident_id', 'number']);
        });

        Schema::create('manv_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manv_incident_id')->constrained('manv_incidents')->cascadeOnDelete();
            $table->dateTime('occurred_at');
            $table->string('action', 100);
            $table->text('description');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 255)->nullable();
            $table->string('subject_type', 50)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->index(['manv_incident_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        foreach (['manv_log', 'manv_patients', 'manv_resources', 'manv_incidents'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
