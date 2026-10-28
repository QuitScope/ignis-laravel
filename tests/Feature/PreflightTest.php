<?php

use App\Auth\ApiKey;
use App\Console\Commands\SchedulerHeartbeatCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('reports the production configuration and fails with exit 1', function (array $config, int $exit, string $check, string $status) {
    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.url' => 'https://ignis.example.org',
        'session.secure' => true,
        'logging.default' => 'single',
        'logging.channels.single.level' => 'warning',
        'ignis.install_token' => '',
        ...$config,
    ]);
    app(ApiKey::class)->current();
    Cache::forever(SchedulerHeartbeatCommand::CACHE_KEY, now()->toIso8601String());

    $code = Artisan::call('ignis:preflight', ['--format' => 'json']);
    $rows = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->keyBy('check');

    expect($code)->toBe($exit)
        ->and($rows[$check]['status'])->toBe($status)
        ->and($rows->pluck('status')->contains('fail'))->toBe($exit === 1);
})->with([
    'production ok' => [[], 0, 'app_url', 'ok'],
    'debug on fails' => [['app.debug' => true], 1, 'app_debug', 'fail'],
]);
