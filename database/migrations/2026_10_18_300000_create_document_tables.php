<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('category', 100)->nullable();
            $table->json('content');
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 7)->unique();
            $table->foreignId('template_id')->nullable()->constrained('document_templates')->nullOnDelete();
            $table->foreignId('personnel_id')->constrained('personnel')->restrictOnDelete();
            $table->string('title', 200);
            $table->json('content');
            $table->enum('status', ['draft', 'issued'])->default('draft')->index();
            $table->json('frozen_values')->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
        });

        Schema::create('legacy_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->string('public_id', 15)->unique();
            $table->tinyInteger('type')->default(0);
            $table->foreignId('personnel_id')->nullable()->constrained('personnel')->restrictOnDelete();
            $table->string('recipient_name', 255)->nullable();
            $table->string('recipient_discord_id', 255)->nullable();
            $table->tinyInteger('salutation')->default(0);
            $table->date('recipient_birthdate')->nullable();
            $table->date('issued_on')->nullable();
            $table->foreignId('issuer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('issuer_discord_id', 255)->nullable();
            $table->string('issuer_name', 255)->nullable();
            $table->boolean('is_archived')->default(false)->index();
            $table->string('pdf_path', 255)->nullable();
            $table->timestamp('pdf_generated_at')->nullable();
            $table->json('legacy_payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['legacy_documents', 'documents', 'document_templates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
