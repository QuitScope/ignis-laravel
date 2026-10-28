<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enotf_crews', function (Blueprint $table) {
            $table->string('pin_hash', 255)->nullable();
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->dateTime('locked_at')->nullable();
            $table->dateTime('last_activity_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('enotf_crews', function (Blueprint $table) {
            $table->dropColumn(['pin_hash', 'failed_attempts', 'locked_at', 'last_activity_at']);
        });
    }
};
