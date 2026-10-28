<?php

namespace App\System;

use App\Models\ScheduledTaskRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Throwable;

/**
 * Writes the run history from the scheduler's events (singleton: it remembers the running row per event). Whatever goes
 * wrong here is swallowed - the history must never stop a task or the scheduler.
 */
class RunHistoryListener
{
    /** @var array<int, ScheduledTaskRun> */
    private array $running = [];

    public function handle(ScheduledTaskStarting|ScheduledTaskFinished|ScheduledTaskFailed|ScheduledTaskSkipped $e): void
    {
        try {
            match (true) {
                $e instanceof ScheduledTaskStarting => $this->running[spl_object_id($e->task)] = ScheduledTaskRun::create(['task' => ScheduleOverview::key($e->task), 'started_at' => now(), 'status' => 'running']),
                $e instanceof ScheduledTaskSkipped => ScheduledTaskRun::create(['task' => ScheduleOverview::key($e->task), 'started_at' => now(), 'finished_at' => now(), 'status' => 'skipped']),
                $e instanceof ScheduledTaskFailed => $this->failed($e),
                default => $this->finish($e->task, ($e->task->exitCode ?? 0) === 0 ? 'success' : 'failed', $e->runtime, $this->output($e->task)),
            };
        } catch (Throwable) {
            // ponytail: swallowed silently; add a log line if lost history ever needs diagnosing
        }
    }

    private function failed(ScheduledTaskFailed $e): void
    {
        $closed = isset($this->running[spl_object_id($e->task)]) ? null : ScheduledTaskRun::query()
            ->where('task', ScheduleOverview::key($e->task))->where('status', 'failed')->where('finished_at', '>=', now()->subMinute())->latest('id')->first();
        if ($closed !== null) {
            $closed->update(['output' => Redact::text($e->exception->getMessage())]);

            return;
        }
        $this->finish($e->task, 'failed', null, $e->exception->getMessage());
    }

    private function finish(Event $task, string $status, ?float $runtime, string $output): void
    {
        $run = $this->running[spl_object_id($task)] ?? new ScheduledTaskRun(['task' => ScheduleOverview::key($task), 'started_at' => now()]);
        unset($this->running[spl_object_id($task)]);
        $run->fill([
            'finished_at' => now(),
            'status' => $status,
            'duration_ms' => (int) round($runtime !== null ? $runtime * 1000 : $run->started_at->diffInMilliseconds(now(), true)),
            'output' => $output === '' ? null : Redact::text($output),
        ])->save();
    }

    private function output(Event $task): string
    {
        $path = $task->output;
        if ($path === '' || $path === $task->getDefaultOutput() || ! is_file($path)) {
            return '';
        }

        return (string) file_get_contents($path, false, null, 0, Redact::OUTPUT_BYTES * 2);
    }
}
