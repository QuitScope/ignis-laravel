<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pois', function (Blueprint $table) {
            $table->string('legacy_identifier', 100)->nullable()->unique()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('pois', function (Blueprint $table) {
            $table->dropUnique(['legacy_identifier']);
            $table->dropColumn('legacy_identifier');
        });
    }
};
