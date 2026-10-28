<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\RunScheduledCommand;
use App\Models\ScheduledTaskRun;
use App\Support\AuditLogger;
use App\System\Redact;
use App\System\ScheduleOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Scheduled tasks (read only, they are code) and the queue. "Run now" takes a command from RunScheduledCommand::ALLOWED
 * and queues it; failed jobs can be retried or deleted. The page never receives a payload, only class, queue, time and
 * a short redacted error.
 */
class ScheduledJobsController extends Controller
{
    private const FAILED_LIMIT = 50;

    public function index(ScheduleOverview $schedule): Response
    {
        $this->authorize('system.admin');

        return Inertia::render('Settings/System/Jobs', [
            'tasks' => $schedule->tasks(),
            'queue' => ['pending' => DB::table('jobs')->count(), 'failed' => DB::table('failed_jobs')->count()],
            'failed' => $this->failed(),
            'commands' => RunScheduledCommand::ALLOWED,
            'history' => Inertia::optional(fn () => ScheduledTaskRun::query()->latest('started_at')->latest('id')->limit(25)->get()
                ->map(fn (ScheduledTaskRun $r) => [
                    'id' => $r->id,
                    'task' => $r->task,
                    'status' => $r->status,
                    'started_at' => $r->started_at->toIso8601String(),
                    'duration_ms' => $r->duration_ms,
                    'output' => $r->output,
                ])->all()),
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $this->authorize('system.admin');
        $command = $request->validate(['command' => ['required', 'string', Rule::in(RunScheduledCommand::ALLOWED)]])['command'];
        dispatch(new RunScheduledCommand($command));
        AuditLogger::log($request->user(), 'Aufgabe manuell gestartet', $command, 'System', true);

        return response()->json(['queued' => true], 202);
    }

    public function retry(Request $request, string $id): RedirectResponse
    {
        $this->authorize('system.admin');
        if (DB::table('failed_jobs')->where('uuid', $id)->exists()) {
            Artisan::call('queue:retry', ['id' => [$id]]);
            AuditLogger::log($request->user(), 'Fehlgeschlagenen Job wiederholt', $id, 'System', true);
        }

        return $this->back('settings.jobs.retried');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $this->authorize('system.admin');
        if (DB::table('failed_jobs')->where('uuid', $id)->delete() > 0) {
            AuditLogger::log($request->user(), 'Fehlgeschlagenen Job gelöscht', $id, 'System', true);
        }

        return $this->back('settings.jobs.deleted');
    }

    public function destroyAll(Request $request): RedirectResponse
    {
        $this->authorize('system.admin');
        $count = DB::table('failed_jobs')->delete();
        AuditLogger::log($request->user(), 'Alle fehlgeschlagenen Jobs gelöscht', (string) $count, 'System', true);

        return $this->back('settings.jobs.deleted_all');
    }

    /** @return list<array{id: string, class: string, queue: string, failed_at: ?string, error: string}> */
    private function failed(): array
    {
        return DB::table('failed_jobs')->orderByDesc('id')->limit(self::FAILED_LIMIT)->get(['uuid', 'queue', 'failed_at', 'payload', 'exception'])
            ->map(function ($j) {
                $name = json_decode((string) $j->payload, true)['displayName'] ?? null;

                return [
                    'id' => (string) $j->uuid,
                    'class' => is_string($name) ? mb_substr($name, 0, 200) : '?',
                    'queue' => (string) $j->queue,
                    'failed_at' => $j->failed_at ? Carbon::parse($j->failed_at, 'UTC')->toIso8601String() : null,
                    'error' => Redact::summary((string) $j->exception),
                ];
            })->all();
    }

    private function back(string $status): RedirectResponse
    {
        return redirect('/settings/system/jobs')->with('status', $status);
    }
}
