<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->nullable()->constrained('personnel_titles')->restrictOnDelete();
            $table->string('full_name', 255);
            $table->date('birth_date');
            $table->string('character_id', 255)->nullable();
            $table->tinyInteger('gender');
            $table->unsignedInteger('forum_profile')->nullable();
            $table->string('discord_id', 32)->nullable()->index();
            $table->string('phone', 255)->nullable();
            $table->string('service_number', 255)->unique();
            $table->date('hired_at');
            $table->foreignId('rank_id')->constrained('ranks')->restrictOnDelete();
            $table->foreignId('fire_qualification_id')->constrained('fire_qualifications')->restrictOnDelete();
            $table->foreignId('ems_qualification_id')->constrained('ems_qualifications')->restrictOnDelete();
            $table->string('extra_qualification', 255)->nullable();
            $table->string('profile_picture', 255)->nullable(); // file name only, no path
            $table->timestamps();
        });

        Schema::create('personnel_specialty', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnDelete();
            $table->foreignId('specialty_id')->constrained('specialties')->restrictOnDelete();
            $table->unique(['personnel_id', 'specialty_id']);
        });

        Schema::create('personnel_log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnDelete();
            $table->tinyInteger('type');
            $table->longText('content');
            $table->string('author', 255);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['personnel_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['personnel_log_entries', 'personnel_specialty', 'personnel'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
