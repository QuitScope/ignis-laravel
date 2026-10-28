<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logbook_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('vehicle_identifier', 64);
            $table->date('trip_date');
            $table->time('departed_at');
            $table->time('arrived_at')->nullable();
            $table->string('station_name', 255)->nullable();
            $table->decimal('distance_km', 8, 1)->default(0);
            $table->text('reason')->nullable();
            $table->string('trip_type', 40);
            $table->string('driver_name', 255);
            $table->enum('source', ['enotf', 'firetab', 'admin']);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('trip_date');
            $table->index('trip_type');
            $table->index('driver_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logbook_entries');
    }
};
