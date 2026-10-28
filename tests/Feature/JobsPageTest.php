<?php

use App\Jobs\RunScheduledCommand;
use App\Models\Role;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function jbUser(string ...$perms): User
{
    $role = Role::create(['name' => 'J'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function jbFailed(string $class = 'App\Jobs\Foo', string $exception = "RuntimeException: boom in /app/x.php:1\nStack trace:\n#0 secret"): string
{
    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'failed_at' => now(),
        'payload' => json_encode(['displayName' => $class, 'data' => ['commandName' => RunScheduledCommand::class, 'command' => serialize(new RunScheduledCommand('ignis:heartbeat'))], 'meta' => 'PAYLOAD1 Bot hunter2 https://discord.com/api/webhooks/1234567890/WHSECRET']),
        'exception' => $exception,
    ]);

    return $uuid;
}

it('is for system admins only', function (string $method, string $url) {
    $this->call($method, $url)->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->call($method, $url, ['command' => 'ignis:heartbeat'])->assertForbidden();
    $this->actingAs(jbUser('personnel.view'))->call($method, $url, ['command' => 'ignis:heartbeat'])->assertForbidden();
})->with([
    'index' => ['GET', '/settings/system/jobs'],
    'run' => ['POST', '/settings/system/jobs/run'],
    'retry' => ['POST', '/settings/system/jobs/failed/abc/retry'],
    'delete' => ['DELETE', '/settings/system/jobs/failed/abc'],
    'delete all' => ['DELETE', '/settings/system/jobs/failed'],
]);

it('lists the schedule, the queue and the failed jobs', function () {
    ScheduledTaskRun::create(['task' => 'ignis:heartbeat', 'started_at' => now(), 'status' => 'success', 'duration_ms' => 7]);
    $uuid = jbFailed();

    $this->actingAs(jbUser('admin'))->get('/settings/system/jobs')->assertInertia(fn (Assert $p) => $p
        ->component('Settings/System/Jobs')
        ->where('tasks', fn ($t) => collect($t)->firstWhere('task', 'ignis:heartbeat')['status'] === 'success')
        ->where('queue.failed', 1)
        ->where('failed.0.id', $uuid)->where('failed.0.class', 'App\Jobs\Foo')->where('failed.0.queue', 'default')
        ->where('failed.0.error', 'RuntimeException: boom in /app/x.php:1')
        ->missing('history')
        ->has('commands', 6));
});

it('loads the history of 25 runs lazily', function () {
    for ($i = 0; $i < 30; $i++) {
        ScheduledTaskRun::create(['task' => 't', 'started_at' => now()->subMinutes($i), 'status' => 'success']);
    }
    $this->actingAs(jbUser('admin'))->get('/settings/system/jobs')
        ->assertInertia(fn (Assert $p) => $p->reloadOnly('history', fn (Assert $r) => $r->has('history', 25)));
});

it('never sends payload or secrets to the page', function () {
    jbFailed(exception: 'Error: call with Bot hunter2 to https://discord.com/api/webhooks/1234567890/WHSECRET failed');
    $json = $this->actingAs(jbUser('admin'))->get('/settings/system/jobs')->getContent();

    foreach (['PAYLOAD1', 'hunter2', 'WHSECRET', 'Stack trace', '&quot;payload&quot;'] as $needle) {
        expect($json)->not->toContain($needle);
    }
    expect($json)->toContain('[redacted]');
});

it('cuts the error to the first line and 200 characters', function () {
    jbFailed(exception: str_repeat('e', 500)."\nline2");
    $this->actingAs(jbUser('admin'))->get('/settings/system/jobs')->assertInertia(fn (Assert $p) => $p->where('failed.0.error', str_repeat('e', 200)));
});

it('queues a whitelisted command and answers 202', function (string $command) {
    Queue::fake();
    $this->actingAs(jbUser('admin'))->postJson('/settings/system/jobs/run', ['command' => $command])->assertStatus(202);
    Queue::assertPushed(RunScheduledCommand::class, fn ($j) => $j->command === $command);
    expect(Activity::where('description', 'Aufgabe manuell gestartet')->count())->toBe(1);
})->with(RunScheduledCommand::ALLOWED);

it('refuses anything else and queues nothing', function (mixed $command) {
    Queue::fake();
    $this->actingAs(jbUser('admin'))->postJson('/settings/system/jobs/run', ['command' => $command])->assertStatus(422);
    Queue::assertNothingPushed();
    expect(Activity::count())->toBe(0);
})->with(['migrate:fresh', 'ignis:heartbeat; ls', 'ignis:heartbeat --force', 'ignis heartbeat', 'IGNIS:HEARTBEAT', 'Notifications:Prune', 'unknown:cmd', '', null, [['ignis:heartbeat']]]);

it('runs the command in the job and records the run redacted', function () {
    Artisan::shouldReceive('call')->once()->with('ignis:heartbeat', [])->andReturn(0);
    Artisan::shouldReceive('output')->once()->andReturn('ok Bot tok.en');
    (new RunScheduledCommand('ignis:heartbeat'))->handle();

    $run = ScheduledTaskRun::sole();
    expect($run->status)->toBe('success')->and($run->output)->toBe('ok Bot [redacted]');
});

it('records a failing command as failed instead of throwing', function () {
    Artisan::shouldReceive('call')->once()->andThrow(new RuntimeException('no such command'));
    (new RunScheduledCommand('ignis:storage:cleanup'))->handle();
    expect(ScheduledTaskRun::sole())->status->toBe('failed')->output->toBe('no such command');
});

it('ignores a command outside the list in the job', function () {
    Artisan::shouldReceive('call')->never();
    (new RunScheduledCommand('migrate:fresh'))->handle();
    expect(ScheduledTaskRun::count())->toBe(0);
});

it('retries one failed job', function () {
    Queue::fake();
    $a = jbFailed();
    $b = jbFailed();
    $this->actingAs(jbUser('admin'))->post("/settings/system/jobs/failed/$a/retry")->assertRedirect('/settings/system/jobs');

    expect(DB::table('failed_jobs')->pluck('uuid')->all())->toBe([$b])
        ->and(Activity::where('description', 'Fehlgeschlagenen Job wiederholt')->count())->toBe(1);
});

it('deletes one failed job and all of them', function () {
    $a = jbFailed();
    jbFailed();
    jbFailed();
    $this->actingAs(jbUser('admin'))->delete("/settings/system/jobs/failed/$a")->assertRedirect('/settings/system/jobs');
    expect(DB::table('failed_jobs')->count())->toBe(2);

    $this->delete('/settings/system/jobs/failed')->assertRedirect('/settings/system/jobs');
    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(Activity::where('description', 'Alle fehlgeschlagenen Jobs gelöscht')->first()->details)->toBe('2');
});

it('does nothing and does not audit for an unknown failed job', function () {
    $this->actingAs(jbUser('admin'))->delete('/settings/system/jobs/failed/nope')->assertRedirect();
    $this->post('/settings/system/jobs/failed/nope/retry')->assertRedirect();
    expect(Activity::count())->toBe(0);
});

it('redirects the old cron and logs urls with 301, GET only', function (string $from) {
    $this->get($from)->assertStatus(301)->assertRedirect('/settings/system/jobs');
    expect($this->post($from)->status())->not->toBe(301);
})->with(['/settings/system/cron', '/settings/system/cron/history', '/settings/system/logs']);

it('keeps the query count constant', function () {
    $admin = jbUser('admin');
    $count = function () use ($admin) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get('/settings/system/jobs')->assertOk();

        return count(DB::getQueryLog());
    };
    $count();
    $base = $count();
    for ($i = 0; $i < 20; $i++) {
        jbFailed();
        ScheduledTaskRun::create(['task' => 'ignis:heartbeat', 'started_at' => now()->subMinutes($i), 'status' => 'success']);
        ScheduledTaskRun::create(['task' => "t$i", 'started_at' => now(), 'status' => 'success']);
    }

    expect($count())->toBe($base);
});

it('prunes failed jobs with the scheduled 720 hours, not the vendor default of 24', function () {
    $row = fn (string $uuid, $at) => ['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => $at];
    DB::table('failed_jobs')->insert([$row('young', now()->subDays(2)), $row('old', now()->subDays(31))]);

    (new RunScheduledCommand('queue:prune-failed'))->handle();

    expect(DB::table('failed_jobs')->pluck('uuid')->all())->toBe(['young'])
        ->and(ScheduledTaskRun::sole()->status)->toBe('success');
});

it('runs with explicit generous timeout and a single try', function () {
    $job = new RunScheduledCommand('ignis:heartbeat');
    expect($job->timeout)->toBe(600)->and($job->tries)->toBe(1);
});
