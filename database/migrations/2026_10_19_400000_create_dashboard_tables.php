<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Quick links (legacy intra_dashboard_categories / _tiles): categories with their links, the FK cascades. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_categories', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->integer('sort_order')->default(0)->index();
            $table->timestamps();
        });

        Schema::create('dashboard_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_category_id')->constrained('dashboard_categories')->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('url', 255);
            $table->string('icon', 100)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['dashboard_category_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_links');
        Schema::dropIfExists('dashboard_categories');
    }
};
