<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lagekarte: markers and zones in map percent (0-100). Account and personnel record are separate FKs (legacy mixed them). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fire_map_markers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fire_incident_id')->constrained('fire_incidents')->cascadeOnDelete();
            $table->string('marker_type', 50);
            $table->decimal('pos_x', 5, 2);
            $table->decimal('pos_y', 5, 2);
            $table->text('description')->nullable();
            foreach (['grundzeichen', 'organisation', 'fachaufgabe', 'einheit', 'symbol', 'text', 'name', 'typ'] as $col) {
                $table->string($col, 100)->nullable();
            }
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->timestamps();

            $table->index('fire_incident_id');
        });

        Schema::create('fire_map_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fire_incident_id')->constrained('fire_incidents')->cascadeOnDelete();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->json('points');
            $table->string('color', 7);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->boolean('is_valid')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fire_map_zones');
        Schema::dropIfExists('fire_map_markers');
    }
};
