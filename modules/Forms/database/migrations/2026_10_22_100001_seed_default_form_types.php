<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Forms\Support\DefaultTypes;

return new class extends Migration
{
    public function up(): void
    {
        DefaultTypes::seed();
    }

    public function down(): void {}
};
