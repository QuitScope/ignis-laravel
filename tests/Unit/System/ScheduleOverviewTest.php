<?php

use App\Models\ScheduledTaskRun;
use App\System\Redact;
use App\System\RunHistoryListener;
use App\System\ScheduleOverview;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function soEvent(string $cmd = 'ignis:heartbeat')
{
    return (new Schedule)->command($cmd)->everyMinute();
}

it('names a command task without the php binary', function () {
    expect(ScheduleOverview::key(soEvent('notifications:prune')))->toBe('notifications:prune');
    expect(ScheduleOverview::key((new Schedule)->call(fn () => 1)->description('Aufräumen')))->toBe('Aufräumen');
});

it('lists the schedule with the last run of each task', function () {
    $schedule = new Schedule;
    $schedule->command('a:one')->daily();
    $schedule->command('b:two')->hourly();
    ScheduledTaskRun::create(['task' => 'a:one', 'started_at' => now()->subHour(), 'status' => 'failed', 'duration_ms' => 5]);
    ScheduledTaskRun::create(['task' => 'a:one', 'started_at' => now(), 'status' => 'success', 'duration_ms' => 12]);

    $tasks = (new ScheduleOverview($schedule, app()))->tasks();

    expect($tasks)->toHaveCount(2)
        ->and($tasks[0])->toMatchArray(['task' => 'a:one', 'expression' => '0 0 * * *', 'status' => 'success', 'duration_ms' => 12])
        ->and($tasks[0]['next_run'])->not->toBeNull()
        ->and($tasks[1])->toMatchArray(['task' => 'b:two', 'status' => null]);
});

it('records start, end, status and duration', function () {
    $l = new RunHistoryListener;
    $e = soEvent();
    $l->handle(new ScheduledTaskStarting($e));
    expect(ScheduledTaskRun::first()->status)->toBe('running');
    $l->handle(new ScheduledTaskFinished($e, 1.5));

    $run = ScheduledTaskRun::sole();
    expect($run->task)->toBe('ignis:heartbeat')->and($run->status)->toBe('success')->and($run->duration_ms)->toBe(1500)->and($run->finished_at)->not->toBeNull();
});

it('records a failure with a redacted message and a skip', function () {
    $l = new RunHistoryListener;
    $e = soEvent();
    $l->handle(new ScheduledTaskStarting($e));
    $l->handle(new ScheduledTaskFailed($e, new RuntimeException('boom https://discord.com/api/webhooks/123/SECRETTOKEN and Bot abc.def.ghi')));
    $l->handle(new ScheduledTaskSkipped(soEvent('x:skipped')));

    $failed = ScheduledTaskRun::where('task', 'ignis:heartbeat')->sole();
    expect($failed->status)->toBe('failed')
        ->and($failed->output)->toContain('boom')->not->toContain('SECRETTOKEN')->not->toContain('abc.def.ghi');
    expect(ScheduledTaskRun::where('task', 'x:skipped')->sole()->status)->toBe('skipped');
});

it('treats a non-zero exit code as failed', function () {
    $e = soEvent();
    $e->exitCode = 1;
    (new RunHistoryListener)->handle(new ScheduledTaskFinished($e, 0.1));
    expect(ScheduledTaskRun::sole()->status)->toBe('failed');
});

it('never throws into the scheduler', function () {
    Schema::drop('scheduled_task_runs');
    (new RunHistoryListener)->handle(new ScheduledTaskStarting(soEvent()));
    expect(true)->toBeTrue();
})->skip(fn () => DB::getDriverName() !== 'sqlite', 'DDL in a transaction');

it('stores at most 4 KB and redacts before cutting', function () {
    $out = str_repeat('ä', 3000).' api/webhooks/123456789/TOKEN'.str_repeat('x', 100);
    (new RunHistoryListener)->handle(new ScheduledTaskFailed(soEvent(), new RuntimeException($out)));
    $stored = ScheduledTaskRun::sole()->output;
    expect(strlen($stored))->toBeLessThanOrEqual(4096)->and(mb_check_encoding($stored, 'UTF-8'))->toBeTrue();

    expect(Redact::text("x api/webhooks/1/a-b_c\nBot   tok.en done"))->toBe("x api/webhooks/[redacted]\nBot [redacted] done");
    expect(Redact::summary("first Bot abc\nsecond"))->toBe('first Bot [redacted]');
    expect(mb_strlen(Redact::summary(str_repeat('é', 500))))->toBe(200);
});

it('prunes after 30 days but keeps the 10 newest runs of each task', function () {
    foreach (['weekly' => 12, 'minutely' => 3] as $task => $n) {
        for ($i = 0; $i < $n; $i++) {
            ScheduledTaskRun::create(['task' => $task, 'started_at' => Carbon::now()->subDays(100 + $i), 'status' => 'success']);
        }
    }
    ScheduledTaskRun::create(['task' => 'minutely', 'started_at' => now()->subDays(40), 'status' => 'success']);
    for ($i = 0; $i < 15; $i++) {
        ScheduledTaskRun::create(['task' => 'busy', 'started_at' => now()->subDays(31 + $i), 'status' => 'success']);
    }
    ScheduledTaskRun::create(['task' => 'busy', 'started_at' => now()->subDays(1), 'status' => 'success']);

    $doomed = (new ScheduledTaskRun)->prunable()->get();

    expect($doomed->where('task', 'weekly'))->toHaveCount(2)
        ->and($doomed->where('task', 'minutely'))->toHaveCount(0)
        ->and($doomed->where('task', 'busy'))->toHaveCount(6)
        ->and($doomed->every(fn ($r) => $r->started_at->lt(now()->subDays(30))))->toBeTrue();
    expect($doomed->contains(fn ($r) => $r->task === 'busy' && $r->started_at->gt(now()->subDays(5))))->toBeFalse();

    (new ScheduledTaskRun)->prunable()->delete();
    expect(ScheduledTaskRun::count())->toBe(10 + 4 + 10);
});

it('keys a task by its command name only', function () {
    expect(ScheduleOverview::key(soEvent('notifications:prune --days=1')))->toBe('notifications:prune');
});

it('records one row for a non-zero exit (Finished, then Failed)', function () {
    $l = new RunHistoryListener;
    $e = soEvent();
    $e->exitCode = 1;
    $l->handle(new ScheduledTaskStarting($e));
    $l->handle(new ScheduledTaskFinished($e, 0.2));
    $l->handle(new ScheduledTaskFailed($e, new RuntimeException('exit 1 Bot abc')));

    $run = ScheduledTaskRun::sole();
    expect($run->status)->toBe('failed')->and($run->output)->toBe('exit 1 Bot [redacted]');
});

it('loads the core schedule even when only the web kernel was booted', function () {
    $previous = Container::getInstance();
    try {
        $app = require base_path('bootstrap/app.php');
        $app->make(Kernel::class)->bootstrap();
        $schedule = $app->make(Schedule::class);
        $before = array_map(fn ($e) => ScheduleOverview::key($e), $schedule->events());
        expect($before)->not->toContain('ignis:heartbeat')->and($before)->not->toContain('notifications:prune');

        $tasks = array_map(fn ($e) => ScheduleOverview::key($e), (new ScheduleOverview($schedule, $app))->events());

        expect($tasks)->toContain('ignis:heartbeat', 'notifications:prune');
    } finally {
        Container::setInstance($previous);
        Facade::setFacadeApplication($previous);
    }
});
