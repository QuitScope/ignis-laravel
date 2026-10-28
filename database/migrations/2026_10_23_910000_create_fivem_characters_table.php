<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One-time tokens for ef_bridge's character identify; the plain token lives only in the account's session.
        Schema::create('fivem_characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('identified_at')->nullable();
            $table->string('char_name', 100)->nullable();
            $table->string('char_job', 50)->nullable();
            $table->unsignedBigInteger('char_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fivem_characters');
    }
};
