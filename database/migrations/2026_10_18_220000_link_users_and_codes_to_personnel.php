<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['personnel_id']);
            $table->foreign('personnel_id')->references('id')->on('personnel')->nullOnDelete();
            $table->unique('personnel_id');
        });

        Schema::table('registration_codes', function (Blueprint $table) {
            $table->foreign('personnel_id')->references('id')->on('personnel')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('registration_codes', function (Blueprint $table) {
            $table->dropForeign(['personnel_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['personnel_id']);
            $table->dropUnique(['personnel_id']);
            $table->index('personnel_id');
        });
    }
};
