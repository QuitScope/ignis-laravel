<?php

namespace App\Console\Commands;

use App\Announcements\AnnouncementFeed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Hourly (routes/console.php). Opt-in: with the switch off nothing is fetched and nothing is touched. */
class RefreshAnnouncementsCommand extends Command
{
    protected $signature = 'announcements:refresh';

    protected $description = 'Fetch the hub announcements (only when switched on) and clean old dismissals';

    public function handle(AnnouncementFeed $feed): int
    {
        if (! $feed->enabled()) {
            $this->line('Hub announcements are switched off.');

            return self::SUCCESS;
        }

        DB::table('announcement_dismissals')->where('dismissed_at', '<', now()->subDays(90))->delete();

        $result = $feed->refresh();
        if (! $result->ok) {
            $this->error("Refresh failed ({$result->message}); the previous announcements stay.");

            return self::FAILURE;
        }
        $this->info("{$result->count} announcements stored, {$result->dropped} dropped.");

        return self::SUCCESS;
    }
}
