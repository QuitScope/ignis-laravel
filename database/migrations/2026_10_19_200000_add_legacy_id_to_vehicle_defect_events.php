<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** id of the imported intra_fahrzeuge_defect_log row; the key of the idempotent legacy import (null for native entries). */
    public function up(): void
    {
        Schema::table('vehicle_defect_events', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_defect_events', function (Blueprint $table) {
            $table->dropUnique(['legacy_id']);
            $table->dropColumn('legacy_id');
        });
    }
};
