<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_id_map', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 50);
            $table->string('legacy_id', 64);
            $table->unsignedBigInteger('new_id');
            $table->unique(['entity', 'legacy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_id_map');
    }
};
