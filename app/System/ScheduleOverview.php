<?php

namespace App\System;

use App\Models\ScheduledTaskRun;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Throwable;

/** The scheduled tasks as code defines them, plus the last recorded run of each (one query, however many tasks). */
class ScheduleOverview
{
    public function __construct(private Schedule $schedule, private Application $app) {}

    /**
     * The core schedule lives in routes/console.php, which only the console kernel loads; the web kernel never does.
     * Bootstrapping it is idempotent (a no-op inside artisan).
     *
     * @return list<Event>
     */
    public function events(): array
    {
        $this->app->make(Kernel::class)->bootstrap();

        return $this->schedule->events();
    }

    public static function key(Event $e): string
    {
        $command = (string) ($e->command ?? '');
        $command = preg_match('~artisan[\'"]?\s+(\S+)~', $command, $m) ? $m[1] : trim($command);

        return mb_substr($command !== '' ? $command : ($e->description ?: $e->getSummaryForDisplay()), 0, 255);
    }

    /** @return list<array{task: string, expression: string, next_run: ?string, status: ?string, last_run: ?string, duration_ms: ?int}> */
    public function tasks(): array
    {
        $last = ScheduledTaskRun::query()
            ->whereIn('id', DB::table('scheduled_task_runs')->selectRaw('max(id)')->groupBy('task'))
            ->get()->keyBy('task');

        return array_map(function (Event $e) use ($last) {
            $run = $last->get(self::key($e));
            try {
                $next = $e->nextRunDate()->toIso8601String();
            } catch (Throwable) {
                $next = null;
            }

            return [
                'task' => self::key($e),
                'expression' => $e->expression,
                'next_run' => $next,
                'status' => $run?->status,
                'last_run' => $run?->started_at?->toIso8601String(),
                'duration_ms' => $run?->duration_ms,
            ];
        }, $this->events());
    }
}
