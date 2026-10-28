<?php

namespace App\Jobs;

use App\Models\ScheduledTaskRun;
use App\System\Redact;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/** "Run now" from the jobs page: one command from the fixed list, fixed arguments only, recorded in the run history. */
class RunScheduledCommand implements ShouldQueue
{
    use Queueable;

    public const ALLOWED = ['notifications:prune', 'announcements:refresh', 'changelog:refresh', 'ignis:heartbeat', 'ignis:storage:cleanup', 'queue:prune-failed'];

    public const PRUNE_FAILED_HOURS = 720;

    public const ARGS = ['queue:prune-failed' => ['--hours' => self::PRUNE_FAILED_HOURS]];

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $command) {}

    public function handle(): void
    {
        if (! in_array($this->command, self::ALLOWED, true)) {
            return;
        }
        $run = ScheduledTaskRun::create(['task' => $this->command, 'started_at' => now(), 'status' => 'running']);
        $started = microtime(true);
        try {
            $status = Artisan::call($this->command, self::ARGS[$this->command] ?? []) === 0 ? 'success' : 'failed';
            $output = Artisan::output();
        } catch (Throwable $e) {
            $status = 'failed';
            $output = $e->getMessage();
        }
        $run->update(['finished_at' => now(), 'status' => $status, 'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'output' => $output === '' ? null : Redact::text($output)]);
    }
}
