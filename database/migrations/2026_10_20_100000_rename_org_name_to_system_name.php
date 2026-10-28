<?php

use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The installation's display name moves from `org.name` to `system.name` (legacy SYSTEM_NAME; `organisation.name`
 * is the organisation in documents). Idempotent; when both rows exist, `system.name` wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->move('org.name', 'system.name');
    }

    public function down(): void
    {
        $this->move('system.name', 'org.name');
    }

    private function move(string $from, string $to): void
    {
        if (DB::table('settings')->where('key', $to)->exists()) {
            DB::table('settings')->where('key', $from)->delete();
        } else {
            DB::table('settings')->where('key', $from)->update(['key' => $to, 'updated_at' => now()]);
        }
        app(Settings::class)->refresh();
    }
};
