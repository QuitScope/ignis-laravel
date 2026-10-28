<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fire_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->nullable()->unique();
            $table->string('location', 255)->nullable();
            $table->decimal('location_x', 14, 9)->nullable();
            $table->decimal('location_y', 14, 9)->nullable();
            $table->string('keyword', 255)->nullable();
            $table->string('caller_name', 255)->nullable();
            $table->string('caller_contact', 255)->nullable();
            $table->string('owner_name', 255)->nullable();
            $table->string('owner_contact', 255)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->foreignId('leader_personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->enum('status', ['new', 'reviewing', 'approved', 'insufficient', 'hidden'])->default('new');
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('archived_at');
            $table->index('started_at');
        });

        Schema::create('fire_incident_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fire_incident_id')->constrained('fire_incidents')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('name', 255)->nullable();
            $table->string('identifier', 255)->nullable();
            $table->string('radio_name', 255)->nullable();
            $table->boolean('is_external')->default(false);
            $table->string('fms_status', 10)->nullable();
            $table->timestamp('fms_status_at')->nullable();
            $table->timestamps();

            $table->unique(['fire_incident_id', 'vehicle_id']);
        });

        Schema::create('fire_sitreps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fire_incident_id')->constrained('fire_incidents')->cascadeOnDelete();
            $table->dateTime('reported_at');
            $table->text('text');
            $table->string('reporter_name', 255)->nullable();
            $table->enum('source', ['local', 'dispatch'])->default('local');
            $table->boolean('is_important')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['fire_incident_id', 'reported_at']);
        });

        Schema::create('fire_incident_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fire_incident_id')->constrained('fire_incidents')->cascadeOnDelete();
            $table->string('action', 50);
            $table->text('description')->nullable();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['fire_incident_id', 'id']);
        });

        Schema::create('fire_status_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('vehicle_name', 255);
            $table->string('incident_number', 50)->nullable();
            $table->string('status', 10);
            $table->timestamp('created_at')->useCurrent();
            $table->string('claim_token', 64)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->index('delivered_at');
            $table->index('claim_token');
        });
    }

    public function down(): void
    {
        foreach (['fire_status_queue', 'fire_incident_log', 'fire_sitreps', 'fire_incident_vehicles', 'fire_incidents'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
