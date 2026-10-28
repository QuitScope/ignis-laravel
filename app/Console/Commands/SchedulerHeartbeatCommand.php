<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduled every minute (routes/console.php): the system overview reads this timestamp to tell whether the scheduler
 * container runs at all.
 */
class SchedulerHeartbeatCommand extends Command
{
    public const CACHE_KEY = 'system.scheduler_heartbeat';

    protected $signature = 'ignis:heartbeat';

    protected $description = 'Record that the scheduler is running';

    public function handle(): int
    {
        Cache::forever(self::CACHE_KEY, now()->toIso8601String());

        return self::SUCCESS;
    }
}
