<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pois', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('street', 255)->nullable();
            $table->string('house_number', 50)->nullable();
            $table->string('city', 255);
            $table->string('district', 255)->nullable();
            $table->string('type', 50)->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->integer('sort_order')->default(0);
            $table->string('identifier', 255)->index();
            $table->string('name', 255)->index();
            $table->string('type', 255)->index();
            $table->unsignedTinyInteger('service')->default(0);
            $table->string('license_plate', 255)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->json('allowed_jobs')->nullable();
            $table->foreignId('station_poi_id')->nullable()->constrained('pois')->nullOnDelete();
            $table->json('tactical_symbol')->nullable();
            $table->string('tactical_symbol_name', 100)->nullable();
            $table->string('fms_status', 10)->nullable();
            $table->dateTime('fms_status_at')->nullable();
            $table->string('fms_status_source', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_defects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('category', 30)->default('other')->index();
            $table->boolean('is_operable')->default(true)->index();
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reporter_name', 200)->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_defect_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_defect_id')->constrained('vehicle_defects')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30);
            $table->text('details')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('loadout_categories', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->unsignedTinyInteger('compartment')->default(0);
            $table->integer('sort_order')->default(0);
            $table->string('vehicle_type', 255)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('loadout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loadout_category_id')->constrained('loadout_categories')->cascadeOnDelete();
            $table->string('title', 255);
            $table->unsignedInteger('quantity')->default(1);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['loadout_category_id', 'sort_order']);
        });

        Schema::create('tactical_symbol_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->json('symbol');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('vehicle_registry_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('emd_vehicle_id')->nullable();
            $table->string('name', 255);
            $table->string('identifier', 255)->nullable();
            $table->string('type', 255)->nullable();
            $table->unsignedTinyInteger('service')->default(0);
            $table->string('department', 255)->nullable();
            $table->string('value_long', 255)->nullable();
            $table->string('job', 255)->nullable();
            $table->string('image', 100)->nullable();
            $table->string('radio_channel', 50)->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('processed_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['vehicle_registry_entries', 'tactical_symbol_templates', 'loadout_items', 'loadout_categories', 'vehicle_defect_events', 'vehicle_defects', 'vehicles', 'pois'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
