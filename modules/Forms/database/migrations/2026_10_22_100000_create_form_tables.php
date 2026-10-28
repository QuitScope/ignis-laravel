<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('icon', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_absence')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_type_id')->constrained('form_types')->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('label', 255);
            $table->enum('type', ['text', 'textarea', 'number', 'date', 'select', 'checkbox', 'email', 'time', 'tel']);
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->string('placeholder', 255)->nullable();
            $table->text('hint')->nullable();
            $table->enum('width', ['full', 'half'])->default('full');
            $table->text('default_value')->nullable();
            $table->boolean('readonly')->default(false);
            $table->enum('auto_fill', ['fullname_dienstnr', 'fullname', 'dienstnr', 'dienstgrad', 'discordtag'])->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['form_type_id', 'key']);
        });

        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 6)->unique();
            $table->foreignId('form_type_id')->constrained('form_types')->restrictOnDelete();
            $table->foreignId('personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->string('applicant_name', 255);
            $table->string('applicant_rank', 255)->nullable();
            $table->string('legacy_discord_id', 64)->nullable();
            $table->enum('status', ['new', 'in_progress', 'deferred', 'accepted', 'rejected'])->default('new');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_name', 255)->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('decided_at')->nullable();
            $table->json('answers');
            $table->timestamps();

            $table->index('status');
            $table->index('personnel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('form_fields');
        Schema::dropIfExists('form_types');
    }
};
