<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A leader picked from a federation peer is kept as a name snapshot "Name [Instanz]" (no FK, no Federation dependency). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fire_incidents', function (Blueprint $table) {
            $table->string('leader_external_name', 150)->nullable()->after('leader_personnel_id');
        });
    }

    public function down(): void
    {
        Schema::table('fire_incidents', function (Blueprint $table) {
            $table->dropColumn('leader_external_name');
        });
    }
};
