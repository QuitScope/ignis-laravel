<?php

use App\Support\Settings;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\UpdateFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    UpdateFixtures::setUp('1.0.0');
});

function uc_settings(): Settings
{
    return app(Settings::class);
}

it('check command stores latest_version/release_url/checked_at', function () {
    uc_settings()->set('updates.last_error', 'old error');
    UpdateFixtures::fake();
    $this->freezeSecond();

    $this->artisan('ignis:update:check')->assertSuccessful();

    expect(uc_settings()->get('updates.latest_version'))->toBe('2.0.0')
        ->and(uc_settings()->get('updates.release_url'))->toBe('https://github.com/ignis-test/ignis/releases/tag/v2.0.0')
        ->and(uc_settings()->get('updates.checked_at'))->toBe(now()->utc()->toIso8601ZuluString())
        ->and(uc_settings()->get('updates.last_error'))->toBe('');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '.zip'));
});

it('reports up to date without an error', function () {
    UpdateFixtures::appDir('2.0.0');
    UpdateFixtures::fake();

    $this->artisan('ignis:update:check')->assertSuccessful();

    expect(uc_settings()->get('updates.latest_version'))->toBe('2.0.0')
        ->and(uc_settings()->get('updates.last_error'))->toBe('');
});

it('still records the latest release on a dev build or Git checkout (the blocker only locks the click)', function () {
    $dir = UpdateFixtures::appDir('dev');
    mkdir("$dir/.git");
    UpdateFixtures::fake();

    $this->artisan('ignis:update:check')->assertSuccessful();

    expect(uc_settings()->get('updates.latest_version'))->toBe('2.0.0')
        ->and(uc_settings()->get('updates.last_error'))->toBe('');
});

it('stores last_error and keeps the last known release on failure', function () {
    uc_settings()->set('updates.latest_version', '1.5.0');
    UpdateFixtures::fake(signature: UpdateFixtures::sign('other'));

    $this->artisan('ignis:update:check')->assertFailed();

    expect(uc_settings()->get('updates.latest_version'))->toBe('1.5.0')
        ->and(uc_settings()->get('updates.last_error'))->toBe(__('update.error.signature'))
        ->and(uc_settings()->get('updates.checked_at'))->not->toBe('');
});

it('check command stores last_error without secrets', function () {
    Http::fake([UpdateFixtures::API => fn () => throw new ConnectionException('cURL error: Bot TOPSECRET '.config('app.key'))]);

    $this->artisan('ignis:update:check')->assertFailed();

    $error = uc_settings()->get('updates.last_error');
    expect($error)->toBe(__('update.error.unreachable'))->not->toContain('TOPSECRET')->not->toContain((string) config('app.key'));
});

it('does not ask GitHub without a repository', function () {
    config(['ignis.release_repo' => '']);
    Http::fake();

    $this->artisan('ignis:update:check')->assertFailed();

    expect(uc_settings()->get('updates.last_error'))->toBe(__('update.error.repo_missing'));
    Http::assertNothingSent();
});

it('scheduled check only when updates.check_enabled', function () {
    /** @var Event $event */
    $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => str_contains($e->command ?? '', 'ignis:update:check'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->filtersPass($this->app))->toBeFalse();

    uc_settings()->set('updates.check_enabled', true);
    expect($event->filtersPass($this->app))->toBeTrue();
});

it('never stores a release older than the installed version as the latest one', function () {
    UpdateFixtures::appDir('3.0.0');
    uc_settings()->set('updates.release_url', 'https://github.com/ignis-test/ignis/releases/tag/v3.0.0');
    UpdateFixtures::fake();

    $this->artisan('ignis:update:check')->assertSuccessful();

    expect(uc_settings()->get('updates.latest_version'))->toBe('3.0.0')
        ->and(uc_settings()->get('updates.release_url'))->toBe('')
        ->and(uc_settings()->get('updates.last_error'))->toBe('');
});
