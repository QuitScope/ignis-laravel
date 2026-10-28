<?php

use App\Models\Role;
use App\Models\User;
use App\Notifications\InboxNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function np_user(string ...$perms): User
{
    $role = Role::create(['name' => 'P'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function np_notify(User $user, string $type = 'system', string $title = 'Hallo', ?string $link = '/documents/1', int $minutesAgo = 0): DatabaseNotification
{
    $n = new InboxNotification($type, $title, 'Text', $link);
    $n->id = (string) Str::uuid();
    $user->notify($n);
    $row = DatabaseNotification::findOrFail($n->id);
    $row->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

    return $row;
}

it('needs a login on every endpoint', function () {
    $this->getJson('/api/notifications/poll')->assertUnauthorized();
    $this->postJson('/api/notifications/mark-read', ['id' => 'x'])->assertUnauthorized();
    $this->postJson('/api/notifications/mark-all-read')->assertUnauthorized();
});

it('answers the poll contract with the newest unread since `since`, at most five', function () {
    $u = np_user();
    foreach (range(1, 7) as $i) {
        np_notify($u, title: "N{$i}", minutesAgo: 10 - $i);
    }
    np_notify($u, title: 'Alt', minutesAgo: 120);
    $read = np_notify($u, title: 'Gelesen', minutesAgo: 1);
    $read->markAsRead();
    np_notify(np_user(), title: 'Fremd');

    $since = now()->subMinutes(30)->utc()->format('Y-m-d H:i:s');
    $r = $this->actingAs($u)->getJson('/api/notifications/poll?since='.urlencode($since))->assertOk();

    $r->assertJson(['success' => true, 'unreadCount' => 8]);
    expect(array_column($r->json('new'), 'title'))->toBe(['N7', 'N6', 'N5', 'N4', 'N3']);
    $item = $r->json('new.0');
    expect(array_keys($item))->toEqualCanonicalizing(['id', 'type', 'title', 'message', 'link', 'created_at', 'known', 'label', 'icon', 'href', 'is_read'])
        ->and($item)->toMatchArray(['type' => 'system', 'message' => 'Text', 'link' => '/documents/1', 'known' => true, 'icon' => 'settings', 'is_read' => false])
        ->and($item['href'])->toBe('/inbox/'.$item['id'].'/open');
})->group('mariadb');

it('accepts ISO-8601 since as UTC and falls back to one minute for invalid or stale values', function (string $since, array $expected) {
    $u = np_user();
    np_notify($u, title: 'vor 5 min', minutesAgo: 5);
    np_notify($u, title: 'jetzt');

    $r = $this->actingAs($u)->getJson('/api/notifications/poll?since='.urlencode($since))->assertOk();
    expect(array_column($r->json('new'), 'title'))->toBe($expected);
})->group('mariadb')->with([
    'iso utc' => fn () => [now()->subMinutes(10)->utc()->toIso8601ZuluString(), ['jetzt', 'vor 5 min']],
    'garbage' => ['nonsense', ['jetzt']],
    'older than 7 days' => fn () => [now()->subDays(8)->utc()->format('Y-m-d H:i:s'), ['jetzt']],
    'empty' => ['', ['jetzt']],
    'future' => fn () => [now()->addDay()->utc()->format('Y-m-d H:i:s'), ['jetzt']],
]);

it('treats since[]= as invalid instead of failing', function () {
    $u = np_user();
    np_notify($u, title: 'jetzt');

    $this->actingAs($u)->getJson('/api/notifications/poll?since[]=1')->assertOk()->assertJsonCount(1, 'new');
});

it('leaves kinds the account may not see out of count and list', function () {
    $u = np_user('audit.view');
    np_notify($u, 'vehicle_defect');
    np_notify($u, 'system');
    $raw = np_notify($u, 'system', 'Roh');
    $raw->forceFill(['type' => 'zz_unregistered'])->save();

    $r = $this->actingAs($u)->getJson('/api/notifications/poll?since='.urlencode(now()->subMinute()->utc()->format('Y-m-d H:i:s')))
        ->assertJson(['unreadCount' => 2]);
    expect(collect($r->json('new'))->firstWhere('title', 'Roh'))->toMatchArray(['known' => false, 'label' => 'zz_unregistered', 'icon' => 'bell']);
})->group('mariadb');

it('marks only own notifications read (IDOR → 404)', function () {
    $u = np_user();
    $mine = np_notify($u);
    $other = np_notify(np_user());

    $this->actingAs($u)->postJson('/api/notifications/mark-read', ['id' => $other->id])->assertNotFound()->assertJson(['success' => false]);
    expect($other->fresh()->read_at)->toBeNull();
    $this->actingAs($u)->postJson('/api/notifications/mark-read', [])->assertUnprocessable();
    $this->actingAs($u)->postJson('/api/notifications/mark-read', ['id' => ['x']])->assertUnprocessable();

    $this->actingAs($u)->postJson('/api/notifications/mark-read', ['id' => $mine->id])->assertOk()->assertJson(['success' => true]);
    expect($mine->fresh()->read_at)->not->toBeNull();
});

it('rejects a malformed id on mark-read with 422 before any query', function (mixed $id) {
    $u = np_user();
    np_notify($u);

    DB::enableQueryLog();
    $this->actingAs($u)->postJson('/api/notifications/mark-read', ['id' => $id])->assertUnprocessable();
    expect(collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'notifications')))->toBeEmpty()
        ->and($u->unreadNotifications()->count())->toBe(1);
})->with(['abc', '12', 12, [['x']]]);

it('marks all own notifications read and nobody else\'s', function () {
    $u = np_user();
    np_notify($u);
    np_notify($u);
    $other = np_notify(np_user());

    $this->actingAs($u)->postJson('/api/notifications/mark-all-read')->assertOk()->assertExactJson(['success' => true]);
    expect($u->unreadNotifications()->count())->toBe(0)->and($other->fresh()->read_at)->toBeNull();
});

it('answers the legacy POST /notifications/mark-read like the API', function () {
    $u = np_user();
    $mine = np_notify($u);

    $this->actingAs($u)->postJson('/notifications/mark-read', ['id' => $mine->id])->assertOk()->assertJson(['success' => true]);
    $this->actingAs($u)->postJson('/benachrichtigungen/mark-read.php', ['id' => np_notify($u)->id])->assertOk();
    expect($u->unreadNotifications()->count())->toBe(0);
});

it('throttles the poll at 20 per minute', function () {
    $u = np_user();
    foreach (range(1, 20) as $i) {
        $this->actingAs($u)->getJson('/api/notifications/poll')->assertOk();
    }
    $this->actingAs($u)->getJson('/api/notifications/poll')->assertTooManyRequests();
});
