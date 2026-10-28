<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Atemschutzüberwachung: only what happened is stored; the state is derived (AsuState). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fire_asu_trupps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fire_incident_id')->constrained('fire_incidents')->cascadeOnDelete();
            $table->string('name', 50);
            $table->enum('role', ['angriff', 'sicherheit'])->default('angriff');
            $table->string('task', 255)->nullable();
            $table->unsignedSmallInteger('expected_minutes')->default(30);
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('fire_asu_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trupp_id')->constrained('fire_asu_trupps')->cascadeOnDelete();
            $table->enum('position', ['tf', 'tm1', 'tm2']);
            $table->string('name', 100);
            $table->foreignId('personnel_id')->nullable()->constrained('personnel')->nullOnDelete();

            $table->unique(['trupp_id', 'position']);
        });

        Schema::create('fire_asu_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fire_incident_id')->constrained('fire_incidents')->cascadeOnDelete();
            $table->foreignId('trupp_id')->constrained('fire_asu_trupps')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('fire_asu_members')->nullOnDelete();
            $table->string('type', 20);
            $table->unsignedSmallInteger('bar')->nullable();
            $table->string('note', 255)->nullable();
            $table->string('client_uuid', 36);
            $table->string('device', 100)->nullable();
            $table->dateTime('occurred_at');
            $table->dateTime('received_at');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unique(['fire_incident_id', 'client_uuid']);
            $table->index(['fire_incident_id', 'id']);
        });

        Schema::create('fire_asu_protocols', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fire_incident_id')->constrained('fire_incidents')->cascadeOnDelete();
            $table->string('supervisor', 255);
            $table->string('mission_location', 255)->nullable();
            $table->date('mission_date')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->json('payload');
            $table->timestamps();

            $table->unique(['fire_incident_id', 'supervisor']);
        });
    }

    public function down(): void
    {
        foreach (['fire_asu_protocols', 'fire_asu_events', 'fire_asu_members', 'fire_asu_trupps'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
