<?php

use App\Announcements\AnnouncementFeed;
use App\Announcements\FeedHttp;
use App\Announcements\FeedTooLarge;
use App\Announcements\WebsiteFeed;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Role;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

const AN_HUB = 'https://emergencyforge.de/api/hub-announcements.php';
const AN_WEB = 'https://emergencyforge.de/c/ankuendigungen/5.json';

function an_user(string ...$perms): User
{
    $role = Role::create(['name' => 'A'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function an_switch(bool $hub = true, bool $website = true): void
{
    app(Settings::class)->set('announcements.hub_enabled', $hub);
    app(Settings::class)->set('announcements.website_enabled', $website);
}

/** @param array<string, mixed> $over */
function an_item(array $over = []): array
{
    return array_merge(['id' => 'a-1', 'type' => 'info', 'title' => 'Wartung', 'message' => 'Heute Abend.', 'link' => null,
        'priority' => 0, 'admin_only' => false, 'valid_from' => null, 'valid_until' => null], $over);
}

function an_fake(mixed $stubs = null): void
{
    Http::swap(new Factory);
    $stubs === null ? Http::fake() : Http::fake($stubs);
}

/** @param list<array<string, mixed>> $items */
function an_hub(array $items, int $status = 200): void
{
    an_fake([AN_HUB => Http::response(['success' => true, 'announcements' => $items], $status)]);
}

function an_cache(array ...$items): void
{
    Cache::forever(AnnouncementFeed::CACHE_KEY, ['fetched_at' => now()->toIso8601String(), 'items' => array_map(fn ($i) => array_merge(an_item(), $i), $items)]);
}

function an_cached(): array
{
    return Cache::get(AnnouncementFeed::CACHE_KEY)['items'] ?? [];
}

// ---- Nothing is sent unless switched on, and never in a web request ----

it('defaults both switches to off', function () {
    expect(app(Settings::class)->get('announcements.hub_enabled', false))->toBeFalse()
        ->and(app(Settings::class)->get('announcements.website_enabled', false))->toBeFalse();
});

it('sends nothing from either command while the switches are off', function () {
    an_fake();

    $this->artisan('announcements:refresh')->assertSuccessful();
    $this->artisan('changelog:refresh')->assertSuccessful();

    Http::assertNothingSent();
    expect(Cache::has(AnnouncementFeed::CACHE_KEY))->toBeFalse()->and(app(Settings::class)->has('announcements.last_error'))->toBeFalse();
});

it('sends nothing from a switch that is off while the other one is on', function () {
    an_fake();
    an_switch(hub: true, website: false);
    $this->artisan('changelog:refresh')->assertSuccessful();
    Http::assertNothingSent();

    an_fake();
    an_switch(hub: false, website: true);
    $this->artisan('announcements:refresh')->assertSuccessful();
    Http::assertNothingSent();
});

it('never calls the network while rendering pages, switches off or on', function (bool $on) {
    an_fake();
    $admin = User::factory()->superAdmin()->create();
    if ($on) {
        an_switch();
        an_cache(['id' => 'x']);
    }

    foreach (['/', '/dashboard', '/inbox', '/settings/system/config?tab=privacy'] as $url) {
        $this->actingAs($admin)->get($url)->assertSuccessful();
    }

    Http::assertNothingSent();
})->with([false, true]);

it('is scheduled hourly and every 30 minutes', function () {
    $events = collect(app(Schedule::class)->events())->mapWithKeys(fn ($e) => [$e->command => $e->expression]);

    expect($events->first(fn ($x, $c) => str_contains($c, 'announcements:refresh')))->toBe('0 * * * *')
        ->and($events->first(fn ($x, $c) => str_contains($c, 'changelog:refresh')))->toBe('*/30 * * * *');
});

// ---- Hub fetch ----

it('fetches the hub without identification and without redirects', function () {
    an_switch(website: false);
    $seen = [];
    an_fake(function (Request $request, array $options) use (&$seen) {
        $seen = ['url' => $request->url(), 'headers' => $request->headers(), 'options' => $options, 'method' => $request->method()];

        return Http::response(['success' => true, 'announcements' => [an_item()]]);
    });

    $this->artisan('announcements:refresh')->assertSuccessful();

    expect($seen['url'])->toBe(AN_HUB)->and($seen['method'])->toBe('GET')
        ->and($seen['options']['timeout'])->toBe(3)
        ->and($seen['options']['allow_redirects'])->toBeFalse()
        ->and($seen['options']['decode_content'])->toBeFalse()
        ->and($seen['headers']['Accept-Encoding'])->toBe(['identity'])
        ->and(array_change_key_case($seen['headers']))->not->toHaveKey('x-installation-id')
        ->and($seen['headers']['User-Agent'])->toBe(['IgnisLaravel'])
        ->and(json_encode($seen['headers']))->not->toContain('nstallation');
    Http::assertSentCount(1);
    expect(an_cached())->toHaveCount(1)->and(Cache::get(AnnouncementFeed::CACHE_KEY)['fetched_at'])->toBeString()
        ->and(app(Settings::class)->get('announcements.last_error'))->toBeNull()
        ->and(app(Settings::class)->get('announcements.last_refreshed_at'))->toBeString();
});

it('accepts a bare list as well as the legacy envelope and ignores the heartbeat field', function () {
    an_fake([AN_HUB => Http::response([an_item(['id' => 'l-1'])])]);
    expect(app(AnnouncementFeed::class)->refresh()->ok)->toBeTrue()->and(an_cached())->toHaveCount(1);

    an_fake([AN_HUB => Http::response(['success' => true, 'announcements' => [an_item(['id' => 'l-2'])], 'heartbeat_requested_at' => '2026-01-01 00:00:00'])]);
    expect(app(AnnouncementFeed::class)->refresh()->ok)->toBeTrue();
});

it('normalises a valid item', function () {
    an_hub([an_item(['type' => 'update', 'link' => 'https://emergencyforge.de/blog/x', 'priority' => 5, 'admin_only' => true,
        'valid_from' => '2026-10-01 08:00:00', 'valid_until' => '2026-11-01T08:00:00+02:00'])]);

    app(AnnouncementFeed::class)->refresh();

    expect(an_cached()[0])->toMatchArray(['id' => 'a-1', 'type' => 'update', 'title' => 'Wartung', 'message' => 'Heute Abend.',
        'link' => 'https://emergencyforge.de/blog/x', 'priority' => 5, 'admin_only' => true,
        'valid_from' => '2026-10-01T08:00:00+00:00', 'valid_until' => '2026-11-01T06:00:00+00:00']);
});

it('drops invalid items, counts them and keeps the valid ones', function () {
    foreach ([
        'unknown type' => [['type' => 'banner']],
        'type not a string' => [['type' => ['info']]],
        'empty title' => [['title' => '']],
        'title too long' => [['title' => str_repeat('t', 256)]],
        'title not a string' => [['title' => 5]],
        'message too long' => [['message' => str_repeat('m', 2001)]],
        'id too long' => [['id' => str_repeat('i', 65)]],
        'id with a space' => [['id' => 'a b']],
        'id with markup' => [['id' => '<b>']],
        'id not a string' => [['id' => ['x']]],
        'javascript link' => [['link' => 'javascript:alert(1)']],
        'http link' => [['link' => 'http://emergencyforge.de/x']],
        'link with credentials' => [['link' => 'https://user:pw@emergencyforge.de/x']],
        'link too long' => [['link' => 'https://emergencyforge.de/'.str_repeat('a', 500)]],
        'relative link' => [['link' => '/blog']],
        'priority out of range' => [['priority' => 101]],
        'priority not an int' => [['priority' => 'high']],
        'admin_only not a bool' => [['admin_only' => 'yes']],
        'unparseable date' => [['valid_from' => 'someday']],
        'date not a string' => [['valid_until' => ['x']]],
    ] as [$bad]) {
        an_hub([an_item(['id' => 'good']), an_item(array_merge(['id' => 'bad'], $bad))]);

        $result = app(AnnouncementFeed::class)->refresh();

        expect($result->ok)->toBeTrue()->and($result->count)->toBe(1)->and($result->dropped)->toBe(1)
            ->and(collect(an_cached())->pluck('id')->all())->toBe(['good']);
    }
});

it('keeps the first of two items with the same id', function () {
    an_hub([an_item(['id' => 'dup', 'title' => 'Erste']), an_item(['id' => 'dup', 'title' => 'Zweite'])]);
    app(AnnouncementFeed::class)->refresh();

    expect(an_cached())->toHaveCount(1)->and(an_cached()[0]['title'])->toBe('Erste');
});

it('stores markup as plain text', function () {
    an_hub([an_item(['title' => '<script>alert(1)</script>Wichtig <b>jetzt</b>', 'message' => "<img src=x onerror=alert(1)>Zeile eins\nZeile\x00 zwei\x07"])]);
    app(AnnouncementFeed::class)->refresh();

    $item = an_cached()[0];
    expect($item['title'])->not->toContain('<')->and($item['title'])->toContain('Wichtig jetzt')
        ->and($item['message'])->not->toContain('<')->and($item['message'])->toBe("Zeile eins\nZeile zwei");
});

it('accepts at most 50 items', function () {
    an_hub(array_map(fn ($i) => an_item(['id' => "i{$i}"]), range(1, 60)));
    $result = app(AnnouncementFeed::class)->refresh();

    expect($result->ok)->toBeTrue()->and(an_cached())->toHaveCount(50)->and($result->dropped)->toBe(10);
});

it('keeps the previous cache and records the error on every failure mode', function (callable $fake, string $error) {
    an_cache(['id' => 'old', 'title' => 'Alt']);
    $fake();

    $result = app(AnnouncementFeed::class)->refresh();

    expect($result->ok)->toBeFalse()->and(an_cached()[0]['id'])->toBe('old')
        ->and(app(Settings::class)->get('announcements.last_error'))->toBe($error)
        ->and(app(Settings::class)->get('announcements.last_refreshed_at'))->toBeString();
})->with([
    'timeout' => [fn () => an_fake(fn () => throw new ConnectionException('cURL error 28 secret-host')), 'unreachable'],
    'server error' => [fn () => an_fake([AN_HUB => Http::response('boom <html>', 500)]), 'http_500'],
    'rate limited' => [fn () => an_fake([AN_HUB => Http::response('', 429)]), 'http_429'],
    'not json' => [fn () => an_fake([AN_HUB => Http::response('<html>nope</html>', 200)]), 'invalid_response'],
    'wrong shape' => [fn () => an_fake([AN_HUB => Http::response(['success' => true, 'announcements' => 'x'])]), 'invalid_response'],
    'hub says failure' => [fn () => an_fake([AN_HUB => Http::response(['success' => false, 'error' => 'secret'])]), 'invalid_response'],
    'redirect to another host' => [fn () => an_fake([AN_HUB => Http::response('', 302, ['Location' => 'https://evil.example/x'])]), 'http_302'],
    'oversize body' => [fn () => an_fake([AN_HUB => Http::response(str_repeat('a', 300 * 1024), 200)]), 'too_large'],
]);

it('does not follow the redirect', function () {
    an_fake(['*' => Http::response('', 302, ['Location' => 'https://evil.example/x'])]);
    app(AnnouncementFeed::class)->refresh();

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example'));
});

it('clears the error after a good refresh and replaces the cache wholesale', function () {
    an_cache(['id' => 'old']);
    app(Settings::class)->set('announcements.last_error', 'http_500');
    an_hub([an_item(['id' => 'new'])]);

    app(AnnouncementFeed::class)->refresh();

    expect(collect(an_cached())->pluck('id')->all())->toBe(['new'])->and(app(Settings::class)->get('announcements.last_error'))->toBeNull();
});

it('replaces the cache with an empty list when the hub has nothing', function () {
    an_cache(['id' => 'old']);
    an_hub([]);

    expect(app(AnnouncementFeed::class)->refresh()->ok)->toBeTrue()->and(an_cached())->toBe([]);
});

it('refuses a hub url that is not https and sends nothing', function () {
    foreach (['http://emergencyforge.de', 'ftp://emergencyforge.de', 'javascript:alert(1)', '//emergencyforge.de', 'https://', 'emergencyforge.de', 'https://u:p@emergencyforge.de', ''] as $url) {
        config(['ignis.hub_url' => $url]);
        an_fake();
        an_cache(['id' => 'old']);

        $result = app(AnnouncementFeed::class)->refresh();

        Http::assertNothingSent();
        expect($result->ok)->toBeFalse()->and(app(Settings::class)->get('announcements.last_error'))->toBe('bad_config')->and(an_cached())->toHaveCount(1);
    }
});

it('uses the configured https host', function () {
    config(['ignis.hub_url' => 'https://hub.example.org/']);
    an_fake(['https://hub.example.org/api/hub-announcements.php' => Http::response([])]);

    expect(app(AnnouncementFeed::class)->refresh()->ok)->toBeTrue();
});

it('deletes dismissals older than 90 days on refresh and only then', function () {
    $u = User::factory()->create();
    DB::table('announcement_dismissals')->insert([
        ['announcement_id' => 'old', 'user_id' => $u->id, 'dismissed_at' => now()->subDays(91)],
        ['announcement_id' => 'recent', 'user_id' => $u->id, 'dismissed_at' => now()->subDays(89)],
    ]);
    an_fake();
    $this->artisan('announcements:refresh');
    expect(DB::table('announcement_dismissals')->count())->toBe(2);

    an_switch();
    an_hub([]);
    $this->artisan('announcements:refresh')->assertSuccessful();
    expect(DB::table('announcement_dismissals')->pluck('announcement_id')->all())->toBe(['recent']);
});

// ---- Website feed ----

function an_topic(array $over = []): array
{
    return array_merge(['id' => 101, 'title' => 'Version 2.1 ist da', 'slug' => 'version-2-1-ist-da', 'created_at' => '2026-10-01T10:00:00.000Z',
        'bumped_at' => '2026-10-02T11:30:00.000Z', 'pinned' => false, 'visible' => true, 'excerpt' => 'Neu: <b>Suche</b> &amp; Inbox'], $over);
}

/** @param list<array<string, mixed>> $topics */
function an_web(array $topics, array $headers = ['ETag' => '"abc"', 'Last-Modified' => 'Wed, 01 Oct 2026 10:00:00 GMT'], int $status = 200): void
{
    an_fake([AN_WEB => Http::response(['topic_list' => ['topics' => $topics]], $status, $headers)]);
}

it('parses a Discourse category listing', function () {
    an_web([
        an_topic(['id' => 7, 'title' => 'Über die Kategorie Ankündigungen', 'slug' => 'ueber-die-kategorie', 'visible' => true]),
        an_topic(['id' => 8, 'title' => 'About the Ankündigungen category', 'slug' => 'about-the']),
        an_topic(['id' => 9, 'title' => 'Versteckt', 'slug' => 'versteckt', 'visible' => false]),
        an_topic(['id' => 10, 'title' => 'Böser Slug', 'slug' => '../x?y=1']),
        an_topic(['id' => 'x', 'title' => 'Keine Zahl', 'slug' => 'a']),
        an_topic(['id' => 11, 'title' => 'Angepinnt', 'slug' => 'angepinnt', 'pinned' => true, 'excerpt' => str_repeat('é', 400)]),
        an_topic(),
    ]);

    $result = app(WebsiteFeed::class)->refresh();

    $items = app(WebsiteFeed::class)->items();
    expect($result->ok)->toBeTrue()->and($items)->toHaveCount(2)
        ->and($items[0])->toMatchArray(['id' => 11, 'title' => 'Angepinnt', 'url' => 'https://emergencyforge.de/t/angepinnt/11', 'pinned' => true])
        ->and(mb_strlen($items[0]['excerpt']))->toBeLessThanOrEqual(300)
        ->and($items[1])->toMatchArray(['id' => 101, 'url' => 'https://emergencyforge.de/t/version-2-1-ist-da/101', 'pinned' => false,
            'excerpt' => 'Neu: Suche & Inbox', 'created_at' => '2026-10-01T10:00:00+00:00', 'bumped_at' => '2026-10-02T11:30:00+00:00']);
});

it('shows at most ten topics', function () {
    an_web(array_map(fn ($i) => an_topic(['id' => $i, 'slug' => "t-{$i}"]), range(1, 14)));
    app(WebsiteFeed::class)->refresh();

    expect(app(WebsiteFeed::class)->items())->toHaveCount(10);
});

it('sends conditional headers from the stored validators and nothing identifying', function () {
    an_switch();
    an_web([an_topic()]);
    $this->artisan('changelog:refresh')->assertSuccessful();
    expect(app(Settings::class)->get('announcements.website_etag'))->toBe('"abc"')
        ->and(app(Settings::class)->get('announcements.website_last_modified'))->toBe('Wed, 01 Oct 2026 10:00:00 GMT');

    $seen = [];
    an_fake(function (Request $request, array $options) use (&$seen) {
        $seen = ['url' => $request->url(), 'headers' => $request->headers(), 'options' => $options];

        return Http::response('', 304);
    });
    $this->artisan('changelog:refresh')->assertSuccessful();

    expect($seen['url'])->toBe(AN_WEB)->and($seen['headers']['If-None-Match'])->toBe(['"abc"'])
        ->and($seen['headers']['If-Modified-Since'])->toBe(['Wed, 01 Oct 2026 10:00:00 GMT'])
        ->and($seen['headers']['User-Agent'])->toBe(['IgnisLaravel'])
        ->and($seen['options']['timeout'])->toBe(5)->and($seen['options']['allow_redirects'])->toBeFalse()
        ->and(json_encode($seen['headers']))->not->toContain('nstallation');
});

it('treats 304 as current: cache stays, timestamp moves, error clears', function () {
    an_web([an_topic()]);
    app(WebsiteFeed::class)->refresh();
    app(Settings::class)->set('announcements.website_last_error', 'http_500');
    app(Settings::class)->set('announcements.website_last_refreshed_at', '2020-01-01T00:00:00+00:00');
    an_fake([AN_WEB => Http::response('', 304)]);

    $result = app(WebsiteFeed::class)->refresh();

    expect($result->ok)->toBeTrue()->and(app(WebsiteFeed::class)->items())->toHaveCount(1)
        ->and(app(Settings::class)->get('announcements.website_last_error'))->toBeNull()
        ->and(app(Settings::class)->get('announcements.website_last_refreshed_at'))->not->toBe('2020-01-01T00:00:00+00:00');
});

it('keeps the website cache on 429, 5xx, network errors and unusable bodies', function (callable $fake, string $error) {
    an_web([an_topic()]);
    app(WebsiteFeed::class)->refresh();
    $fake();

    $result = app(WebsiteFeed::class)->refresh();

    expect($result->ok)->toBeFalse()->and(app(WebsiteFeed::class)->items())->toHaveCount(1)
        ->and(app(Settings::class)->get('announcements.website_last_error'))->toBe($error);
})->with([
    '429' => [fn () => an_fake([AN_WEB => Http::response('', 429)]), 'http_429'],
    '503' => [fn () => an_fake([AN_WEB => Http::response('', 503)]), 'http_503'],
    '404' => [fn () => an_fake([AN_WEB => Http::response('', 404)]), 'http_404'],
    'network' => [fn () => an_fake(fn () => throw new ConnectionException('down')), 'unreachable'],
    'not json' => [fn () => an_fake([AN_WEB => Http::response('<html>', 200)]), 'invalid_response'],
    'no topic_list' => [fn () => an_fake([AN_WEB => Http::response(['errors' => ['x']])]), 'invalid_response'],
    'redirect' => [fn () => an_fake([AN_WEB => Http::response('', 301, ['Location' => 'https://evil.example/'])]), 'http_301'],
]);

it('refuses a forum url that is not https', function () {
    config(['ignis.forum_url' => 'http://emergencyforge.de']);
    an_fake();

    expect(app(WebsiteFeed::class)->refresh()->ok)->toBeFalse();
    Http::assertNothingSent();
});

// ---- Display ----

function an_ids(User $user): array
{
    return array_column(app(AnnouncementFeed::class)->visibleTo($user), 'id');
}

it('shows nothing while the hub switch is off', function () {
    an_cache(['id' => 'x']);

    expect(an_ids(User::factory()->create()))->toBe([]);
    $this->actingAs(User::factory()->create())->get('/')->assertInertia(fn (Assert $p) => $p->where('announcements', []));
});

it('filters by validity window, admin_only and dismissal', function () {
    an_switch(website: false);
    $u = an_user();
    an_cache(
        ['id' => 'open'],
        ['id' => 'current', 'valid_from' => now()->subDay()->toIso8601String(), 'valid_until' => now()->addDay()->toIso8601String()],
        ['id' => 'future', 'valid_from' => now()->addHour()->toIso8601String()],
        ['id' => 'expired', 'valid_until' => now()->subMinute()->toIso8601String()],
        ['id' => 'admin', 'admin_only' => true],
        ['id' => 'gone'],
    );
    DB::table('announcement_dismissals')->insert(['announcement_id' => 'gone', 'user_id' => $u->id, 'dismissed_at' => now()]);

    expect(an_ids($u))->toBe(['open', 'current'])
        ->and(an_ids(an_user('admin')))->toContain('admin', 'gone')->not->toContain('future', 'expired')
        ->and(an_ids(User::factory()->superAdmin()->create()))->toContain('admin');
});

it('sorts by priority, then severity, and shows at most five', function () {
    an_switch(website: false);
    an_cache(
        ['id' => 'info-0', 'type' => 'info'], ['id' => 'crit-0', 'type' => 'critical'], ['id' => 'warn-0', 'type' => 'warning'],
        ['id' => 'upd-0', 'type' => 'update'], ['id' => 'ok-0', 'type' => 'success'], ['id' => 'info-9', 'type' => 'info', 'priority' => 9],
        ['id' => 'info-neg', 'type' => 'info', 'priority' => -5],
    );

    expect(an_ids(User::factory()->create()))->toBe(['info-9', 'crit-0', 'warn-0', 'upd-0', 'ok-0']);
});

it('shares the announcements with the layout and only the fields the banner needs', function () {
    an_switch(website: false);
    an_cache(['id' => 'x', 'type' => 'warning', 'title' => 'T', 'message' => 'M', 'link' => 'https://emergencyforge.de/a', 'admin_only' => false]);

    $this->actingAs(User::factory()->create())->get('/')->assertInertia(fn (Assert $p) => $p
        ->where('announcements', [['id' => 'x', 'type' => 'warning', 'title' => 'T', 'message' => 'M', 'link' => 'https://emergencyforge.de/a']]));
});

it('does not query dismissals when the cache is empty or the switch is off', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->get('/');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->actingAs($u)->get('/', ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);
    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'announcement_dismissals')))->toBeEmpty();

    an_switch(website: false);
    DB::flushQueryLog();
    $this->actingAs($u)->get('/');
    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'announcement_dismissals')))->toBeEmpty();
});

// ---- Dismiss ----

it('needs a login to dismiss', function () {
    $this->postJson('/api/announcements/dismiss', ['announcement_id' => 'a'])->assertUnauthorized();
});

it('dismisses per account and idempotently', function () {
    an_switch(website: false);
    an_cache(['id' => 'a-1']);
    [$one, $two] = [User::factory()->create(), User::factory()->create()];

    foreach ([1, 2] as $_) {
        $this->actingAs($one)->postJson('/api/announcements/dismiss', ['announcement_id' => 'a-1'])->assertOk()->assertExactJson(['success' => true]);
    }

    expect(DB::table('announcement_dismissals')->where(['announcement_id' => 'a-1', 'user_id' => $one->id])->count())->toBe(1)
        ->and(an_ids($one))->toBe([])->and(an_ids($two))->toBe(['a-1']);
    $this->actingAs($two)->postJson('/api/announcements/dismiss', ['announcement_id' => 'a-1'])->assertOk();
    expect(DB::table('announcement_dismissals')->count())->toBe(2);
});

it('answers success for an id it does not know without storing it', function () {
    an_switch(website: false);
    an_cache(['id' => 'a-1']);

    $this->actingAs(User::factory()->create())->postJson('/api/announcements/dismiss', ['announcement_id' => 'nope'])->assertOk()->assertExactJson(['success' => true]);
    expect(DB::table('announcement_dismissals')->count())->toBe(0);
});

it('rejects a malformed announcement_id with 422', function () {
    foreach ([[null], [''], [['a']], [5], [str_repeat('a', 65)], ['a b'], ['<b>'], ['a/b']] as [$id]) {
        $this->actingAs(User::factory()->create())->postJson('/api/announcements/dismiss', ['announcement_id' => $id])->assertUnprocessable();
    }
});

it('rejects a missing announcement_id with 422', function () {
    $this->actingAs(User::factory()->create())->postJson('/api/announcements/dismiss', [])->assertUnprocessable();
});

it('answers the legacy /api/dismiss-announcement path like the API', function () {
    an_switch(website: false);
    an_cache(['id' => 'a-1']);
    $u = User::factory()->create();

    $this->actingAs($u)->postJson('/api/dismiss-announcement', ['announcement_id' => 'a-1'])->assertOk()->assertExactJson(['success' => true]);
    $this->actingAs($u)->postJson('/api/dismiss-announcement.php', ['announcement_id' => 'a-1'])->assertOk();

    expect(DB::table('announcement_dismissals')->count())->toBe(1);
});

it('throttles the dismiss at 30 per minute', function () {
    $u = User::factory()->create();
    foreach (range(1, 30) as $_) {
        $this->actingAs($u)->postJson('/api/announcements/dismiss', ['announcement_id' => 'a'])->assertOk();
    }
    $this->actingAs($u)->postJson('/api/announcements/dismiss', ['announcement_id' => 'a'])->assertTooManyRequests();
});

it('deletes the dismissals with the account', function () {
    $u = User::factory()->create();
    DB::table('announcement_dismissals')->insert(['announcement_id' => 'a', 'user_id' => $u->id, 'dismissed_at' => now()]);
    $u->delete();

    expect(DB::table('announcement_dismissals')->count())->toBe(0);
});

// ---- Dashboard widget ----

it('shows the website entries to admins only, and only with the switch on', function () {
    an_web([an_topic()]);
    app(WebsiteFeed::class)->refresh();
    $admin = an_user('admin');
    $other = an_user('vehicles.view', 'personnel.view');

    $this->actingAs($admin)->get('/')->assertInertia(fn (Assert $p) => $p->where('layout.side', fn ($side) => ! in_array('announcements', $side->all())));

    an_switch(hub: false, website: true);
    $this->actingAs($admin)->get('/')->assertInertia(fn (Assert $p) => $p
        ->where('layout.side', fn ($side) => in_array('announcements', $side->all()))
        ->where('widgets.announcements.items.0.title', 'Version 2.1 ist da')
        ->where('widgets.announcements.items.0.url', 'https://emergencyforge.de/t/version-2-1-ist-da/101')
        ->where('widgets.announcements.problem', null));
    $this->actingAs($other)->get('/')->assertInertia(fn (Assert $p) => $p->where('layout.side', fn ($side) => ! in_array('announcements', $side->all())));

    $this->actingAs($other)->get('/', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Inertia-Partial-Component' => 'Home', 'X-Inertia-Partial-Data' => 'widgets.announcements'])
        ->assertOk()->assertJsonMissingPath('props.widgets.announcements.items');
});

it('shows the failure to admins, and an empty state when there is nothing at all', function () {
    an_switch(hub: false, website: true);
    $admin = an_user('admin');

    $this->actingAs($admin)->get('/')->assertInertia(fn (Assert $p) => $p->where('widgets.announcements', ['items' => [], 'problem' => null, 'blog_url' => 'https://emergencyforge.de/blog']));

    app(Settings::class)->set('announcements.website_last_error', 'http_503');
    $this->actingAs($admin)->get('/')->assertInertia(fn (Assert $p) => $p->where('widgets.announcements.problem', 'http_503')->where('widgets.announcements.items', []));
});

// ---- Settings UI ----

it('shows the switches to the organisation admin only', function () {
    $this->put('/settings/system/config/privacy', [])->assertRedirect('/login');
    $this->actingAs(an_user('personnel.view'))->get('/settings/system/config?tab=privacy')->assertForbidden();
    $this->actingAs(an_user('personnel.view'))->put('/settings/system/config/privacy', ['announcements.hub_enabled' => true, 'announcements.website_enabled' => true])->assertForbidden();

    $this->actingAs(an_user('admin'))->get('/settings/system/config?tab=privacy')->assertInertia(fn (Assert $p) => $p
        ->where('groups.privacy.0.value', false)->where('groups.privacy.1.value', false)
        ->where('hosts', ['emergencyforge.de'])
        ->where('announcements', []));
});

it('saves the switches and audits each change', function () {
    $admin = an_user('admin');

    $this->actingAs($admin)->put('/settings/system/config/privacy', ['announcements.hub_enabled' => true, 'announcements.website_enabled' => false])->assertRedirect();
    expect(app(Settings::class)->get('announcements.hub_enabled'))->toBeTrue()->and(app(Settings::class)->get('announcements.website_enabled', false))->toBeFalse()
        ->and(Activity::where('description', 'Ankündigungen aktiviert (Hub)')->where('log_name', 'Organisation')->count())->toBe(1)
        ->and(Activity::where('description', 'like', '%Website%')->count())->toBe(0);

    $this->actingAs($admin)->put('/settings/system/config/privacy', ['announcements.hub_enabled' => true, 'announcements.website_enabled' => true])->assertRedirect();
    $this->actingAs($admin)->put('/settings/system/config/privacy', ['announcements.hub_enabled' => false, 'announcements.website_enabled' => true])->assertRedirect();
    expect(Activity::where('description', 'Ankündigungen aktiviert (Hub)')->count())->toBe(1)
        ->and(Activity::where('description', 'Ankündigungen aktiviert (Website)')->count())->toBe(1)
        ->and(Activity::where('description', 'Ankündigungen deaktiviert (Hub)')->count())->toBe(1);
});

it('validates the switches as booleans', function () {
    $this->actingAs(an_user('admin'))->put('/settings/system/config/privacy', ['announcements.hub_enabled' => 'maybe', 'announcements.website_enabled' => ['x']])
        ->assertSessionHasErrors(['announcements.hub_enabled', 'announcements.website_enabled']);
    expect(app(Settings::class)->has('announcements.hub_enabled'))->toBeFalse();
});

// ---- Fix round 1 ----

it('guards the download size', function () {
    FeedHttp::guard(0, FeedHttp::MAX_BYTES);
    expect(fn () => FeedHttp::guard(0, FeedHttp::MAX_BYTES + 1))->toThrow(FeedTooLarge::class);
});

it('reports a wrapped size abort as too_large', function () {
    an_fake(fn () => throw new ConnectionException('cURL error 42', 0, new FeedTooLarge('x')));

    expect(app(AnnouncementFeed::class)->refresh()->message)->toBe('too_large');
});

it('treats a gzip body it did not decode as an invalid response', function () {
    an_fake([AN_HUB => Http::response(gzencode(json_encode([an_item()])), 200)]);

    expect(app(AnnouncementFeed::class)->refresh()->message)->toBe('invalid_response');
});

it('accepts the loose forms a MySQL-backed hub sends', function () {
    foreach ([
        'int id' => [['id' => 42], ['id' => '42']],
        'admin_only 1' => [['admin_only' => 1], ['admin_only' => true]],
        'admin_only "1"' => [['admin_only' => '1'], ['admin_only' => true]],
        'admin_only 0' => [['admin_only' => 0], ['admin_only' => false]],
        'admin_only "0"' => [['admin_only' => '0'], ['admin_only' => false]],
        'priority string' => [['priority' => '7'], ['priority' => 7]],
        'priority negative string' => [['priority' => '-7'], ['priority' => -7]],
        'empty link' => [['link' => ''], ['link' => null]],
        'empty dates' => [['valid_from' => '', 'valid_until' => ''], ['valid_from' => null, 'valid_until' => null]],
    ] as [$over, $expect]) {
        an_hub([an_item(array_merge(['id' => 'x'], $over))]);

        $result = app(AnnouncementFeed::class)->refresh();

        expect($result->ok)->toBeTrue()->and($result->dropped)->toBe(0)->and(an_cached()[0])->toMatchArray($expect);
    }
});

it('still drops what is not a plain boolean or number', function () {
    foreach ([
        'admin_only "false"' => [['admin_only' => 'false']],
        'admin_only 2' => [['admin_only' => 2]],
        'admin_only ""' => [['admin_only' => '']],
        'admin_only null' => [['admin_only' => null]],
        'priority "high"' => [['priority' => 'high']],
        'priority "1.5"' => [['priority' => '1.5']],
        'priority "101"' => [['priority' => '101']],
        'priority float' => [['priority' => 1.5]],
        'float id' => [['id' => 1.5]],
        'bool id' => [['id' => true]],
    ] as [$bad]) {
        an_hub([an_item(array_merge(['id' => 'bad'], $bad))]);

        expect(app(AnnouncementFeed::class)->refresh()->dropped)->toBe(1);
    }
});

it('decodes entities before stripping tags, so escaped markup never survives', function () {
    an_hub([an_item(['title' => 'Hallo &lt;img src=x onerror=alert(1)&gt;', 'message' => '&lt;script&gt;x&lt;/script&gt;Text'])]);
    app(AnnouncementFeed::class)->refresh();
    expect(an_cached()[0]['title'])->toBe('Hallo')->and(an_cached()[0]['message'])->toBe('xText');

    an_web([an_topic(['title' => 'Neu &lt;b&gt;fett&lt;/b&gt; &amp; gut', 'excerpt' => '&lt;img onerror=x&gt;Vorschau'])]);
    app(WebsiteFeed::class)->refresh();
    expect(app(WebsiteFeed::class)->items()[0])->toMatchArray(['title' => 'Neu fett & gut', 'excerpt' => 'Vorschau']);
});

it('keeps dismissals case-sensitive', function () {
    $u = User::factory()->create();
    DB::table('announcement_dismissals')->insert([
        ['announcement_id' => 'Abc', 'user_id' => $u->id, 'dismissed_at' => now()],
        ['announcement_id' => 'abc', 'user_id' => $u->id, 'dismissed_at' => now()],
    ]);

    expect(DB::table('announcement_dismissals')->count())->toBe(2);
});

it('names the configured hosts for the privacy note', function () {
    config(['ignis.hub_url' => 'https://hub.example.org', 'ignis.forum_url' => 'https://forum.example.org']);
    $this->actingAs(an_user('admin'))->get('/settings/system/config?tab=privacy')->assertInertia(fn (Assert $p) => $p->where('hosts', ['hub.example.org', 'forum.example.org']));
});

it('forgets the cache of a feed when it is switched off', function () {
    $admin = an_user('admin');
    an_switch();
    an_cache(['id' => 'x']);
    an_web([an_topic()]);
    app(WebsiteFeed::class)->refresh();
    expect(app(Settings::class)->get('announcements.website_etag'))->toBe('"abc"');

    $this->actingAs($admin)->put('/settings/system/config/privacy', ['announcements.hub_enabled' => false, 'announcements.website_enabled' => true])->assertRedirect();
    expect(Cache::has(AnnouncementFeed::CACHE_KEY))->toBeFalse()->and(Cache::has(WebsiteFeed::CACHE_KEY))->toBeTrue();

    $this->actingAs($admin)->put('/settings/system/config/privacy', ['announcements.hub_enabled' => false, 'announcements.website_enabled' => false])->assertRedirect();
    expect(Cache::has(WebsiteFeed::CACHE_KEY))->toBeFalse()
        ->and(app(Settings::class)->get('announcements.website_etag'))->toBeNull()
        ->and(app(Settings::class)->get('announcements.website_last_modified'))->toBeNull();
});

it('sends no conditional headers when the cache is gone', function () {
    an_web([an_topic()]);
    app(WebsiteFeed::class)->refresh();
    Cache::forget(WebsiteFeed::CACHE_KEY);
    $seen = [];
    an_fake(function (Request $request) use (&$seen) {
        $seen = $request->headers();

        return Http::response(['topic_list' => ['topics' => [an_topic()]]]);
    });

    app(WebsiteFeed::class)->refresh();

    expect(array_change_key_case($seen))->not->toHaveKey('if-none-match')->not->toHaveKey('if-modified-since')
        ->and(app(WebsiteFeed::class)->items())->toHaveCount(1);
});
