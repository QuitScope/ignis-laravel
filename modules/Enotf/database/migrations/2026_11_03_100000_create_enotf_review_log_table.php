<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enotf_review_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enotf_protocol_id')->constrained('enotf_protocols')->cascadeOnDelete();
            $table->enum('kind', ['status', 'comment', 'reopen']);
            $table->unsignedTinyInteger('from_status')->nullable();
            $table->unsignedTinyInteger('to_status')->nullable();
            $table->text('text')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 255);
            $table->dateTime('created_at');
            $table->index(['enotf_protocol_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enotf_review_log');
    }
};
