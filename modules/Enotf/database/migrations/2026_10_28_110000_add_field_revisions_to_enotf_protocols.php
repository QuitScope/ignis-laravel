<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enotf_protocols', function (Blueprint $table) {
            $table->json('field_revisions')->nullable()->after('revision');
        });
    }

    public function down(): void
    {
        Schema::table('enotf_protocols', function (Blueprint $table) {
            $table->dropColumn('field_revisions');
        });
    }
};
