<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enotf_crew_members', function (Blueprint $table) {
            $table->unsignedBigInteger('active_key')->nullable()->unique();
        });

        $seen = [];
        DB::table('enotf_crew_members as m')->join('enotf_crews as c', 'c.id', '=', 'm.enotf_crew_id')->whereNull('m.left_at')
            ->orderByDesc('m.id')->get(['m.id', 'm.personnel_id', 'c.ended_at'])
            ->each(function (object $row) use (&$seen) {
                $keep = $row->ended_at === null && ! isset($seen[$row->personnel_id]);
                if ($keep) {
                    $seen[$row->personnel_id] = true;
                }
                DB::table('enotf_crew_members')->where('id', $row->id)
                    ->update($keep ? ['active_key' => $row->personnel_id] : ['left_at' => $row->ended_at ?? now()]);
            });
    }

    public function down(): void
    {
        Schema::table('enotf_crew_members', function (Blueprint $table) {
            $table->dropUnique(['active_key']);
            $table->dropColumn('active_key');
        });
    }
};
