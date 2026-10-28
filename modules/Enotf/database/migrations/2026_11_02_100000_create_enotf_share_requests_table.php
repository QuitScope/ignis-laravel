<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enotf_share_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protocol_id')->constrained('enotf_protocols')->cascadeOnDelete();
            $table->foreignId('from_vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('to_vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'accepted', 'rejected', 'cancelled', 'expired'])->default('pending');
            $table->json('payload');
            $table->foreignId('target_protocol_id')->nullable()->constrained('enotf_protocols')->nullOnDelete();
            $table->dateTime('expires_at');
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['to_vehicle_id', 'status']);
            $table->index(['protocol_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enotf_share_requests');
    }
};
