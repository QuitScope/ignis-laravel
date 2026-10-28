<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->boolean('is_global')->default(false);
            $table->text('details')->nullable();
            $table->index(['is_global', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['is_global', 'created_at']);
            $table->dropColumn(['is_global', 'details']);
        });
    }
};
