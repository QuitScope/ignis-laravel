<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Federation: links to peer instances, the read-only caches and the sync run log. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('federation_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('instance_id')->unique();
            $table->string('name', 100);
            $table->string('url', 255);
            $table->text('secret_outgoing');
            $table->text('secret_incoming');
            foreach (['personnel', 'enotf', 'fire'] as $type) {
                $table->boolean("consume_{$type}")->default(false);
                $table->boolean("provide_{$type}")->default(false);
            }
            $table->unsignedSmallInteger('sync_interval_minutes')->default(15);
            $table->boolean('is_active')->default(true);
            $table->timestamp('confirmed_at')->nullable();
            $table->json('cursors')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_sync_status', 16)->default('pending');
            $table->string('last_sync_error', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('federation_personnel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained('federation_links')->cascadeOnDelete();
            $table->unsignedBigInteger('remote_id');
            $table->string('full_name', 255);
            $table->string('service_number', 50)->nullable();
            $table->string('rank', 255)->nullable();
            $table->json('qualifications')->nullable();
            $table->uuid('seen_run')->nullable();
            $table->timestamps();
            $table->unique(['link_id', 'remote_id']);
        });

        Schema::create('federation_enotf_protocols', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained('federation_links')->cascadeOnDelete();
            $table->unsignedBigInteger('remote_id');
            $table->string('number', 100)->nullable();
            $table->dateTime('occurred_at')->nullable();
            $table->json('data')->nullable();
            $table->dateTime('remote_updated_at')->nullable();
            $table->timestamps();
            $table->unique(['link_id', 'remote_id']);
            $table->index('remote_updated_at');
        });

        Schema::create('federation_fire_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained('federation_links')->cascadeOnDelete();
            $table->unsignedBigInteger('remote_id');
            $table->string('number', 100)->nullable();
            $table->string('keyword', 255)->nullable();
            $table->string('location', 255)->nullable();
            $table->string('status', 50)->nullable();
            $table->dateTime('occurred_at')->nullable();
            $table->json('data')->nullable();
            $table->dateTime('remote_updated_at')->nullable();
            $table->timestamps();
            $table->unique(['link_id', 'remote_id']);
            $table->index('remote_updated_at');
        });

        Schema::create('federation_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained('federation_links')->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('status', 16);
            $table->unsignedInteger('records')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('error', 64)->nullable(); // a code, never response content
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        foreach (['federation_sync_runs', 'federation_fire_incidents', 'federation_enotf_protocols', 'federation_personnel', 'federation_links'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
