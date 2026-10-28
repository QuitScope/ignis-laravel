<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // iCal subscription: only the SHA-256 of the token, never the token itself. Module migrations always
    // run (also while the module is off), hence the guard.
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'calendar_token_hash')) {
            Schema::table('users', fn (Blueprint $table) => $table->char('calendar_token_hash', 64)->nullable()->unique());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'calendar_token_hash')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['calendar_token_hash']);
                $table->dropColumn('calendar_token_hash');
            });
        }
    }
};
