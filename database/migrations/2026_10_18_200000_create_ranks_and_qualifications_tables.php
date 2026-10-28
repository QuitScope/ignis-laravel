<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ranks', function (Blueprint $table) {
            $table->id();
            $table->integer('priority');
            $table->string('name', 255);
            $table->string('name_male', 255);
            $table->string('name_female', 255);
            $table->string('badge', 255)->nullable();
            $table->boolean('is_archive')->default(false);
            $table->timestamps();
        });

        Schema::create('fire_qualifications', function (Blueprint $table) {
            $table->id();
            $table->integer('priority');
            $table->string('short_name', 255);
            $table->string('name', 255);
            $table->string('name_male', 255);
            $table->string('name_female', 255);
            $table->boolean('is_none')->default(false);
            $table->timestamps();
        });

        Schema::create('ems_qualifications', function (Blueprint $table) {
            $table->id();
            $table->integer('priority');
            $table->string('name', 255);
            $table->string('name_male', 255);
            $table->string('name_female', 255);
            $table->string('abbreviation', 50)->nullable();
            $table->boolean('is_none')->default(false);
            $table->boolean('is_trainable')->default(false);
            $table->timestamps();
        });

        Schema::create('specialties', function (Blueprint $table) {
            $table->id();
            $table->integer('number');
            $table->string('name', 255);
            $table->boolean('is_disabled')->default(false);
            $table->timestamps();
        });

        Schema::create('personnel_titles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->integer('priority')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['personnel_titles', 'specialties', 'ems_qualifications', 'fire_qualifications', 'ranks'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
