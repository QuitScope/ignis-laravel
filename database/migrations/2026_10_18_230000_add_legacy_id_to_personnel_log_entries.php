<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** logid of the imported intra_mitarbeiter_log row; the key of the idempotent legacy import (null for native entries). */
    public function up(): void
    {
        Schema::table('personnel_log_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('personnel_log_entries', function (Blueprint $table) {
            $table->dropUnique(['legacy_id']);
            $table->dropColumn('legacy_id');
        });
    }
};
