<?php

use App\Models\Role;
use App\Models\User;
use App\Notifications\InboxNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function ib_user(string ...$perms): User
{
    $role = Role::create(['name' => 'I'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function ib_notify(User $user, string $type = 'system', ?string $link = '/documents/1', string $title = 'Hallo'): DatabaseNotification
{
    $n = new InboxNotification($type, $title, null, $link);
    $n->id = (string) Str::uuid();
    $user->notify($n);

    return DatabaseNotification::findOrFail($n->id);
}

it('needs a login', function () {
    $id = (string) Str::uuid();
    foreach (['/inbox', '/inbox/popover', "/inbox/{$id}/open"] as $url) {
        $this->get($url)->assertRedirect('/login');
    }
    $this->post('/inbox/read')->assertRedirect('/login');
});

it('lists own notifications with segment counts, filters and 25 per page', function () {
    $u = ib_user('vehicles.view');
    foreach (range(1, 27) as $i) {
        ib_notify($u, 'system');
    }
    ib_notify($u, 'vehicle_defect')->markAsRead();
    ib_notify(ib_user(), 'system');

    $this->actingAs($u)->get('/inbox')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Inbox/Index')
        ->has('entries.data', 25)
        ->where('entries.total', 28)
        ->where('counts.all', 28)
        ->where('counts.unread', 27)
        ->where('counts.types', ['' => 28, 'system' => 27, 'vehicle_defect' => 1])
        ->where('unread', 27)
        ->has('types', 4)
        ->has('entries.data.0', fn (Assert $e) => $e->hasAll(['id', 'type', 'title', 'message', 'link', 'created_at', 'known', 'label', 'icon', 'href', 'is_read'])));

    $this->actingAs($u)->get('/inbox?filter=unread&type=vehicle_defect')->assertInertia(fn (Assert $p) => $p
        ->has('entries.data', 0)
        ->where('filters', ['filter' => 'unread', 'type' => 'vehicle_defect'])
        ->where('counts.all', 1)
        ->where('counts.unread', 0)
        ->where('counts.types', ['' => 27, 'system' => 27]));

    $this->actingAs(ib_user())->get('/inbox?type=vehicle_defect&filter[]=x')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('filters', ['filter' => '', 'type' => '']));
})->group('mariadb');

it('hides kinds the account may not see from page, popover and shared counter alike', function () {
    $u = ib_user('audit.view');
    ib_notify($u, 'vehicle_defect');
    ib_notify($u, 'system');

    $this->actingAs($u)->get('/inbox')->assertInertia(fn (Assert $p) => $p
        ->has('entries.data', 1)->where('unread', 1)->where('notifications.unread', 1));
    $this->actingAs($u)->getJson('/inbox/popover')->assertOk()->assertJson(['unread' => 1])->assertJsonCount(1, 'entries');
})->group('mariadb');

it('serves the popover: the last five, read or not, plus the counter', function () {
    $u = ib_user();
    foreach (range(1, 6) as $i) {
        ib_notify($u);
    }
    ib_notify($u)->markAsRead();

    $this->actingAs($u)->getJson('/inbox/popover')->assertOk()->assertJsonCount(5, 'entries')->assertJson(['unread' => 6]);
});

it('opens only own notifications, marks them read and goes to internal links only', function () {
    $u = ib_user();
    $own = ib_notify($u, link: '/documents/7');
    $foreign = ib_notify(ib_user());

    $this->actingAs($u)->get("/inbox/{$foreign->id}/open")->assertNotFound();
    expect($foreign->fresh()->read_at)->toBeNull();
    $this->actingAs($u)->get('/inbox/not-a-uuid/open')->assertNotFound();

    $this->actingAs($u)->get("/inbox/{$own->id}/open")->assertRedirect('/documents/7');
    expect($own->fresh()->read_at)->not->toBeNull();

    $evil = ib_notify($u);
    $evil->forceFill(['data' => ['title' => 'x', 'message' => null, 'link' => 'https://evil.example/']])->save();
    $this->actingAs($u)->get("/inbox/{$evil->id}/open")->assertRedirect('/inbox');
    $none = ib_notify($u, link: null);
    $this->actingAs($u)->get("/inbox/{$none->id}/open")->assertRedirect('/inbox');
});

it('marks one or all read over POST /inbox/read and returns only to internal paths', function () {
    $u = ib_user();
    $a = ib_notify($u);
    ib_notify($u);
    $foreign = ib_notify(ib_user());

    $this->actingAs($u)->post('/inbox/read', ['id' => $foreign->id, 'return' => 'inbox?filter=unread'])
        ->assertRedirect('/inbox?filter=unread');
    expect($foreign->fresh()->read_at)->toBeNull();

    $this->actingAs($u)->post('/inbox/read', ['id' => $a->id, 'return' => '//evil.example'])
        ->assertRedirect('/inbox')->assertSessionHas('status', 'inbox.marked_one');
    expect($a->fresh()->read_at)->not->toBeNull()->and($u->unreadNotifications()->count())->toBe(1);

    $this->actingAs($u)->post('/inbox/read', ['return' => 'https://evil.example'])->assertRedirect('/inbox')
        ->assertSessionHas('status', 'inbox.marked_all')->assertSessionHas('status_params', ['count' => 1]);
    expect($u->unreadNotifications()->count())->toBe(0)->and($foreign->fresh()->read_at)->toBeNull();

    $this->actingAs($u)->post('/inbox/read', ['id' => ['x'], 'return' => ['x']])->assertRedirect('/inbox');
});

it('answers a malformed id on POST /inbox/read like "already read" without touching any row', function (mixed $id) {
    $u = ib_user();
    ib_notify($u);

    DB::enableQueryLog();
    $this->actingAs($u)->post('/inbox/read', ['id' => $id])->assertRedirect('/inbox')->assertSessionHas('status', 'inbox.already_read');
    $touched = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'notifications'));

    expect($touched)->toBeEmpty()->and($u->unreadNotifications()->count())->toBe(1);
})->with(['abc', '12', 'not-a-uuid-at-all', [['x']]]);

it('drops a return path with control characters', function (string $return) {
    $this->actingAs(ib_user())->post('/inbox/read', ['return' => $return])->assertRedirect('/inbox');
})->with(["inbox?x=\x00y", "inbox?x=\x01y", "inbox?x=\x7Fy"]);

it('throttles the popover at 60 per minute', function () {
    $u = ib_user();
    foreach (range(1, 60) as $i) {
        $this->actingAs($u)->getJson('/inbox/popover')->assertOk();
    }
    $this->actingAs($u)->getJson('/inbox/popover')->assertTooManyRequests();
});

it('shares the unread counter with every page', function () {
    $u = ib_user();
    ib_notify($u);
    ib_notify($u)->markAsRead();

    $this->actingAs($u)->get('/')->assertInertia(fn (Assert $p) => $p->where('notifications.unread', 1));
});

it('prunes only notifications read more than 180 days ago, daily', function () {
    $u = ib_user();
    $old = ib_notify($u);
    $old->forceFill(['read_at' => now()->subDays(181)])->save();
    $recent = ib_notify($u);
    $recent->forceFill(['read_at' => now()->subDays(179)])->save();
    $unread = ib_notify($u);
    $unread->forceFill(['created_at' => now()->subYear()])->save();

    $this->artisan('notifications:prune')->assertSuccessful();
    expect(DatabaseNotification::pluck('id')->all())->toEqualCanonicalizing([$recent->id, $unread->id]);

    $this->travel(2)->days();
    $this->artisan('notifications:prune', ['--days' => 180])->assertSuccessful();
    expect(DatabaseNotification::pluck('id')->all())->toBe([$unread->id]);

    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'notifications:prune'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 0 * * *');
})->group('mariadb');

it('prunes notifications of deleted accounts, unread or not, and leaves those of existing accounts', function () {
    $gone = ib_user();
    $kept = ib_user();
    ib_notify($gone);
    ib_notify($gone)->forceFill(['read_at' => now()])->save();
    $mine = ib_notify($kept);
    $gone->delete();
    expect(DatabaseNotification::count())->toBe(3);

    $this->artisan('notifications:prune')->assertSuccessful();

    expect(DatabaseNotification::pluck('id')->all())->toBe([$mine->id]);
})->group('mariadb');

it('redirects the legacy notification pages to /inbox', function (string $from) {
    $this->actingAs(ib_user())->get($from)->assertStatus(301)->assertRedirect('/inbox');
})->with(['/notifications', '/notifications/', '/notifications/index', '/notifications/index.php', '/benachrichtigungen', '/benachrichtigungen/index.php']);
