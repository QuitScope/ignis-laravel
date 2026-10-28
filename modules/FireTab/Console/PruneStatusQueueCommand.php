<?php

namespace Modules\FireTab\Console;

use App\Modules\ModuleCommand;
use Modules\FireTab\Models\FireStatusQueue;

/** Status queue cleanup: delivered rows after 7 days, undelivered (never fetched or claimed and lost) after 1 h. Hourly. */
class PruneStatusQueueCommand extends ModuleCommand
{
    protected $signature = 'firetab:prune-status-queue {--dry-run : Count only, delete nothing}';

    protected $description = 'Delete old rows of the FMS status queue';

    public function module(): string
    {
        return 'firetab';
    }

    public function handle(): int
    {
        $delivered = FireStatusQueue::query()->whereNotNull('delivered_at')->where('delivered_at', '<', now()->subDays(7));
        $stale = FireStatusQueue::query()->whereNull('delivered_at')->where('created_at', '<', now()->subHour());

        if ($this->option('dry-run')) {
            $this->info("{$delivered->count()} delivered and {$stale->count()} undelivered row(s) would be deleted (dry run).");

            return self::SUCCESS;
        }

        $d = $delivered->delete();
        $s = $stale->delete();
        $this->info("$d delivered and $s undelivered row(s) deleted.");

        return self::SUCCESS;
    }
}
