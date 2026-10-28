<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Times: timed events are UTC instants; all-day events hold dates (00:00:00, end inclusive), see CalendarEvent.
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('location', 255)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('all_day')->default(false);
            $table->enum('color', ['accent', 'blue', 'green', 'red', 'purple', 'gray'])->default('accent');
            $table->enum('category', ['general', 'meeting', 'training', 'duty', 'absence'])->default('general');
            $table->enum('visibility', ['private', 'attendees', 'role', 'all'])->default('attendees');
            $table->boolean('track_attendance')->default(false);
            if (Schema::hasTable('form_submissions')) {
                $table->foreignId('form_submission_id')->nullable()->unique()->constrained('form_submissions')->cascadeOnDelete();
            } else {
                $table->unsignedBigInteger('form_submission_id')->nullable()->unique();
            }
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recurrence_rule', 255)->nullable();
            $table->dateTime('recurrence_until')->nullable();
            $table->timestamps();

            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('calendar_event_role', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->primary(['event_id', 'role_id']);
        });

        Schema::create('calendar_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnDelete();
            $table->enum('response', ['pending', 'accepted', 'declined', 'tentative'])->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->boolean('is_organizer')->default(false);
            $table->timestamps();

            $table->unique(['event_id', 'personnel_id']);
            $table->index('personnel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_attendees');
        Schema::dropIfExists('calendar_event_role');
        Schema::dropIfExists('calendar_events');
    }
};
