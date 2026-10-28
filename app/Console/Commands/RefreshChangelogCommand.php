<?php

namespace App\Console\Commands;

use App\Announcements\WebsiteFeed;
use Illuminate\Console\Command;

/** Every 30 minutes (routes/console.php). Opt-in: with the switch off nothing is fetched. */
class RefreshChangelogCommand extends Command
{
    protected $signature = 'changelog:refresh';

    protected $description = 'Fetch the website announcements for the start page (only when switched on)';

    public function handle(WebsiteFeed $feed): int
    {
        if (! $feed->enabled()) {
            $this->line('Website announcements are switched off.');

            return self::SUCCESS;
        }

        $result = $feed->refresh();
        if (! $result->ok) {
            $this->error("Refresh failed ({$result->message}); the previous entries stay.");

            return self::FAILURE;
        }
        $this->info("{$result->count} entries stored.");

        return self::SUCCESS;
    }
}
