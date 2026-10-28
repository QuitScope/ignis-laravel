<?php

use App\Update\HealthGate;
use App\Update\UpdateException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

function uhDatabase(): void
{
    $dir = sys_get_temp_dir().'/ignis-uh-'.bin2hex(random_bytes(4));
    mkdir($dir);
    register_shutdown_function(fn () => (new Filesystem)->deleteDirectory($dir));
    touch("$dir/db.sqlite");
    config(['database.connections.update_test' => ['driver' => 'sqlite', 'database' => "$dir/db.sqlite", 'prefix' => '', 'foreign_key_constraints' => true], 'database.default' => 'update_test']);
    $c = DB::connection('update_test');
    foreach (['create table migrations (id integer primary key, migration text, batch integer)', 'create table jobs (id integer primary key)', 'create table failed_jobs (id integer primary key)'] as $sql) {
        $c->statement($sql);
    }
    $c->table('migrations')->insert(['migration' => '0001_01_01_000000_create_users_table', 'batch' => 1]);
}

it('the health probe command reports version, down checks and pending migrations as JSON', function () {
    uhDatabase();

    Artisan::call('ignis:update:health');
    $report = json_decode(trim(Artisan::output()), true);

    expect($report)->toHaveKeys(['version', 'down', 'queue', 'pending', 'http'])
        ->and($report['down'])->toBe([])
        ->and($report['http'])->toBe(200)
        ->and($report['queue'])->toBe('ok')
        ->and($report['pending'])->toBeGreaterThan(0);
});

it('the health gate reads the command report: version mismatch and pending migrations fail', function () {
    uhDatabase();
    $probe = function (): array {
        Artisan::call('ignis:update:health');

        return (array) json_decode(trim(Artisan::output()), true);
    };
    $gate = new HealthGate($probe, 1, 1);

    expect(fn () => $gate->check('9.9.9', false, false, base_path()))->toThrow(fn (UpdateException $e) => expect($e->getMessage())->toContain('instead of 9.9.9'));
    $version = $probe()['version'];
    expect(fn () => $gate->check($version, false, false, base_path()))->toThrow(fn (UpdateException $e) => expect($e->getMessage())->toContain('migrations pending'));
});

it('the health probe reports the HTTP status of /healthz; the gate fails on anything but 200, and tolerates an old probe without it', function () {
    uhDatabase();
    Route::get('/healthz', fn () => response('broken', 500));
    app('router')->getRoutes()->refreshNameLookups();

    Artisan::call('ignis:update:health');
    $report = json_decode(trim(Artisan::output()), true);
    expect($report['http'])->toBe(500);

    $base = ['version' => '2.0.0', 'down' => [], 'queue' => 'ok', 'pending' => 0];
    $gate = fn (array $r) => new HealthGate(fn () => $r, 1, 1);
    expect(fn () => $gate($base + ['http' => 500])->check('2.0.0', false, false, base_path()))
        ->toThrow(fn (UpdateException $e) => expect($e->getMessage())->toContain('HTTP 500'));
    $gate($base + ['http' => 200])->check('2.0.0', false, false, base_path());
    $gate($base)->check('2.0.0', false, false, base_path());
    expect(true)->toBeTrue();
});
