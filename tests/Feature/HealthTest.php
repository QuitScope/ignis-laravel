<?php

use App\Support\HealthChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\UpdateFixtures;

uses(RefreshDatabase::class);

it('reports the live VERSION file without a config rebuild', function () {
    config(['ignis.version' => 'stale-from-cache']);
    $dir = UpdateFixtures::appDir('1.0.0');

    $this->getJson('/api/version')->assertJsonPath('version', '1.0.0');
    $this->getJson('/api/health')->assertJsonPath('version', '1.0.0');

    file_put_contents("$dir/VERSION", "1.1.0\n");
    $this->getJson('/api/version')->assertJsonPath('version', '1.1.0');
    $this->getJson('/api/health')->assertJsonPath('version', '1.1.0');
});

it('serves the health report on both routes without auth', function (string $uri) {
    config(['ignis.version' => 'v9.9.9']);

    $this->getJson($uri)
        ->assertOk()
        ->assertJsonStructure([
            'status',
            'checks' => ['db' => ['status', 'ms'], 'queue' => ['status', 'pending', 'failed'], 'storage' => ['status', 'free_mb'], 'migrations' => ['status', 'latest'], 'php_extensions', 'rewrite'],
            'version',
            'checked_at',
        ])
        ->assertJsonPath('version', 'v9.9.9')
        ->assertJsonPath('checks.db.status', 'ok');
})->with(['/healthz', '/api/health']);

it('answers 503 with db down instead of 500 when the database is unreachable', function () {
    config(['session.driver' => 'database']);
    $default = config('database.default');
    config(['database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/x.sqlite', 'prefix' => '']]);
    config(['database.default' => 'broken']);
    DB::purge('broken');

    try {
        $this->getJson('/healthz')
            ->assertStatus(503)
            ->assertJsonPath('status', 'down')
            ->assertJsonPath('checks.db.status', 'down')
            ->assertJsonPath('checks.migrations.status', 'down')
            ->assertJsonPath('checks.queue.status', 'down')
            ->assertCookieMissing(config('session.cookie'));
    } finally {
        config(['database.default' => $default]);
        DB::purge('broken');
    }
});

it('sets no session cookie on /healthz', function () {
    config(['session.driver' => 'database']);

    $this->getJson('/healthz')->assertOk()->assertCookieMissing(config('session.cookie'));
});

it('serves the version in the legacy shape', function () {
    config(['ignis.version' => 'v1.2.3']);

    $this->getJson('/api/version')->assertOk()->assertExactJson(['version' => 'v1.2.3', 'system' => 'ignis']);
});

it('aggregates the overall status', function () {
    expect(HealthChecks::overall(['a' => ['status' => 'ok']]))->toBe('ok')
        ->and(HealthChecks::overall(['a' => ['status' => 'ok'], 'b' => ['status' => 'degraded']]))->toBe('degraded')
        ->and(HealthChecks::overall(['a' => ['status' => 'degraded'], 'b' => ['status' => 'down']]))->toBe('down');
});

it('rewrite check accepts the front controller path or a document root of public/', function () {
    $check = fn (array $server) => HealthChecks::run(Request::create('/healthz', 'GET', [], [], [], $server))['checks']['rewrite'];

    expect($check(['SCRIPT_FILENAME' => 'C:\app\public\index.php'])['front_controller'])->toBeTrue()
        ->and($check(['SCRIPT_FILENAME' => '/herd/server.php', 'DOCUMENT_ROOT' => public_path()])['front_controller'])->toBeTrue()
        ->and($check(['SCRIPT_FILENAME' => '/herd/server.php', 'DOCUMENT_ROOT' => base_path()])['front_controller'])->toBeFalse()
        ->and($check(['SCRIPT_FILENAME' => '/herd/server.php'])['front_controller'])->toBeFalse();
});
