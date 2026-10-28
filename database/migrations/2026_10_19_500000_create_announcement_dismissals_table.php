<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who hid which hub announcement (legacy intra_global_announcements_dismissed); rows older than 90 days are cleaned by announcements:refresh. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_dismissals', function (Blueprint $table) {
            $table->id();
            $table->string('announcement_id', 64)->collation(Schema::getConnection()->getDriverName() === 'sqlite' ? null : 'utf8mb4_bin');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('dismissed_at')->useCurrent()->index();
            $table->unique(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_dismissals');
    }
};
