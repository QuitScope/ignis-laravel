<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('kb_categories')->nullOnDelete();
            $table->string('name', 100);
            $table->string('icon', 50)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('kb_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('color', 7);
            $table->timestamps();
        });

        Schema::create('kb_entries', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['general', 'medication', 'measure'])->default('general');
            $table->foreignId('category_id')->nullable()->constrained('kb_categories')->nullOnDelete();
            $table->string('title', 255);
            $table->string('subtitle', 255)->nullable();
            $table->enum('competency_level', ['basis', 'rettsan', 'notsan_2c', 'notsan_2a', 'notarzt'])->nullable();
            $table->json('content')->nullable();
            $table->json('sections')->nullable();
            $table->string('active_ingredient', 255)->nullable();
            $table->string('drug_class', 255)->nullable();
            $table->longText('search_text');
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->boolean('hide_editor')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_archived', 'is_pinned']);
            $table->index('type');
            $table->index('category_id');
        });

        Schema::create('kb_entry_tag', function (Blueprint $table) {
            $table->foreignId('entry_id')->constrained('kb_entries')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('kb_tags')->cascadeOnDelete();
            $table->primary(['entry_id', 'tag_id']);
        });

        // A pair is one row with entry_id < related_entry_id. SQLite has no CHECK here and relies on
        // KbEntryRelation::normalize() (every writer must use it); MariaDB also enforces it with the CHECK below.
        Schema::create('kb_entry_relations', function (Blueprint $table) {
            $table->foreignId('entry_id')->constrained('kb_entries')->cascadeOnDelete();
            $table->foreignId('related_entry_id')->constrained('kb_entries')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['entry_id', 'related_entry_id']);
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE kb_entry_relations ADD CONSTRAINT kb_entry_relations_order CHECK (entry_id < related_entry_id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_entry_relations');
        Schema::dropIfExists('kb_entry_tag');
        Schema::dropIfExists('kb_entries');
        Schema::dropIfExists('kb_tags');
        Schema::dropIfExists('kb_categories');
    }
};
