<?php

use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/calendarHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('applies the four visibility levels in feed, detail and policy alike', function () {
    $creator = cal_user(['calendar.view', 'calendar.create']);
    $roleA = cal_role();
    $roleB = cal_role();
    $invited = cal_user();
    $member = cal_user(['calendar.view'], true, [$roleB]);
    $other = cal_user();

    $private = cal_event(['visibility' => 'private', 'created_by' => $creator->id], [], [$invited->personnel_id => 'pending']);
    $attendees = cal_event(['visibility' => 'attendees', 'created_by' => $creator->id], [], [$invited->personnel_id => 'pending']);
    $role = cal_event(['visibility' => 'role', 'created_by' => $creator->id], [$roleA, $roleB]);
    $all = cal_event(['visibility' => 'all', 'created_by' => $creator->id]);

    $expect = [
        [$creator, [$private, $attendees, $role, $all]],
        [$invited, [$attendees, $all]],
        [$member, [$role, $all]],
        [$other, [$all]],
    ];
    foreach ($expect as [$user, $visible]) {
        $ids = array_map(fn ($e) => $e->id, $visible);
        sort($ids);
        expect(cal_feed_ids($user))->toBe($ids);
        foreach ([$private, $attendees, $role, $all] as $e) {
            $this->actingAs($user)->getJson("/calendar/{$e->id}")->assertStatus(in_array($e->id, $ids, true) ? 200 : 404);
            expect($user->can('view', $e))->toBe(in_array($e->id, $ids, true));
        }
    }
});

it('shows everything to calendar.manage, admin and super admins, also in the list', function () {
    $owner = cal_user();
    $events = [cal_event(['visibility' => 'private', 'created_by' => $owner->id]), cal_event(['visibility' => 'role', 'created_by' => $owner->id], [cal_role()])];
    $ids = collect($events)->pluck('id')->sort()->values()->all();

    foreach ([cal_user(['calendar.manage']), cal_user(['admin']), User::factory()->superAdmin()->create()] as $user) {
        expect(cal_feed_ids($user))->toBe($ids);
    }
});

it('shows nothing without calendar.view, not even own events', function () {
    $user = cal_user(['calendar.create']);
    $e = cal_event(['visibility' => 'all', 'created_by' => $user->id]);

    $this->actingAs($user)->get('/calendar')->assertForbidden();
    $this->actingAs($user)->getJson('/calendar/events?from=2026-11-01&to=2026-12-01')->assertForbidden();
    $this->actingAs($user)->getJson("/calendar/{$e->id}")->assertNotFound();
});

it('sends guests to the login', function () {
    $e = cal_event();

    foreach (['/calendar', '/calendar/events?from=2026-11-01&to=2026-12-01', '/calendar/personnel', "/calendar/{$e->id}"] as $url) {
        $this->get($url)->assertRedirect('/login');
    }
    $this->post('/calendar', [])->assertRedirect('/login');
});

it('does not count an invitation on a role or private event as visibility', function () {
    $invited = cal_user();
    $e = cal_event(['visibility' => 'role'], [cal_role()], [$invited->personnel_id => 'accepted']);

    expect(cal_feed_ids($invited))->toBe([]);
});

it('answers 404 on every calendar route while the module is off', function () {
    $user = cal_user(['calendar.view', 'calendar.create']);
    $e = cal_event(['created_by' => $user->id]);
    app(Settings::class)->set('modules.calendar.enabled', false);

    $this->actingAs($user)->get('/calendar')->assertNotFound();
    $this->actingAs($user)->getJson('/calendar/events?from=2026-11-01&to=2026-12-01')->assertNotFound();
    $this->actingAs($user)->getJson('/calendar/personnel?q=a')->assertNotFound();
    $this->actingAs($user)->getJson("/calendar/{$e->id}")->assertNotFound();
    $this->actingAs($user)->deleteJson("/calendar/{$e->id}")->assertNotFound();
    expect($user->can('view', $e))->toBeFalse();
});

it('renders the page shell with rights, roles and the requested event', function () {
    $role = cal_role();
    $this->actingAs(cal_user(['calendar.view', 'calendar.create']))->get('/calendar?event=12')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Calendar/Index')
            ->where('can', ['create' => true, 'manage' => false])
            ->where('openEvent', 12)
            ->where('timezone', 'Europe/Berlin')
            ->where('roles', fn ($roles) => collect($roles)->contains('id', $role->id))
            ->missing('personnel'));
});
