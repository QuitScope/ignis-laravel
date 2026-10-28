<?php

namespace Modules\Federation\Console;

use App\Modules\ModuleCommand;
use Modules\Federation\Jobs\SyncLink;
use Modules\Federation\Models\FederationLink;

/** Queues a SyncLink job per due link (active, consumes something, interval passed); --link forces one. Every 5 min via schedule(). */
class SyncFederationCommand extends ModuleCommand
{
    protected $signature = 'federation:sync {--link= : Sync this link now}';

    protected $description = 'Queue federation sync jobs for due links';

    public function module(): string
    {
        return 'federation';
    }

    public function handle(): int
    {
        $forced = $this->option('link');
        $links = FederationLink::query()->where('is_active', true)
            ->when($forced !== null, fn ($q) => $q->whereKey((int) $forced))
            ->get()
            ->filter(fn (FederationLink $l) => $forced !== null || (($l->consume_personnel || $l->consume_enotf || $l->consume_fire)
                && ($l->last_sync_at === null || $l->last_sync_at->copy()->addMinutes($l->sync_interval_minutes)->lte(now()))));

        foreach ($links as $link) {
            SyncLink::dispatch($link->id);
        }
        $this->info((string) $links->count());

        return self::SUCCESS;
    }
}
