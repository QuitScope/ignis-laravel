<?php

use App\Models\User;
use App\Notifications\InboxNotification;
use App\Notifications\NotificationKinds;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/calendarHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();
});

function cal_base(array $over = []): array
{
    return $over + ['title' => 'Dienst', 'starts_at' => '2026-11-10T18:00', 'ends_at' => '2026-11-10T20:00', 'location' => 'Wache'];
}

it('registers the kind calendar for calendar.view', function () {
    $kinds = app(NotificationKinds::class);

    expect($kinds->has('calendar'))->toBeTrue()
        ->and($kinds->allowed(cal_user()))->toContain('calendar')
        ->and($kinds->allowed(cal_user(['calendar.create'])))->not->toContain('calendar');
});

it('notifies the invited accounts but not the creator on create, with a link to the event', function () {
    $creator = cal_user(['calendar.view', 'calendar.create']);
    $guest = cal_user();

    $id = $this->actingAs($creator)->postJson('/calendar', cal_base(['visibility' => 'attendees', 'attendee_ids' => [$guest->personnel_id, $creator->personnel_id]]))->json('id');

    Notification::assertSentTo($guest, InboxNotification::class, function (InboxNotification $n) use ($guest, $id) {
        $data = $n->toArray($guest);

        return $n->databaseType($guest) === 'calendar' && $data['link'] === "/calendar?event={$id}"
            && $data['title'] === 'Termin angelegt: Dienst' && $data['message'] === '10.11.2026 18:00 · Wache';
    });
    Notification::assertNotSentTo($creator, InboxNotification::class);
});

it('notifies again only when time, place or title change', function () {
    $creator = cal_user(['calendar.view', 'calendar.create']);
    $guest = cal_user();
    $payload = cal_base(['visibility' => 'attendees', 'attendee_ids' => [$guest->personnel_id]]);
    $id = $this->actingAs($creator)->postJson('/calendar', $payload)->json('id');

    foreach ([['description' => 'Neu'], ['color' => 'red'], ['category' => 'duty'], []] as $change) {
        $this->actingAs($creator)->putJson("/calendar/{$id}", $payload + $change)->assertOk();
    }
    Notification::assertSentToTimes($guest, InboxNotification::class, 1);

    foreach ([['title' => 'Dienst 2'], ['location' => 'Halle'], ['starts_at' => '2026-11-10T17:00'], ['ends_at' => '2026-11-10T21:00']] as $i => $change) {
        $this->actingAs($creator)->putJson("/calendar/{$id}", $change + $payload)->assertOk();
        Notification::assertSentToTimes($guest, InboxNotification::class, $i + 2);
    }
});

it('notifies role members only with attendance tracking', function () {
    $creator = cal_user(['calendar.view', 'calendar.create']);
    $role = cal_role();
    $member = cal_user(['calendar.view'], false, [$role]);
    $inactive = User::factory()->inactive()->create();
    $inactive->assignRole($role);

    $this->actingAs($creator)->postJson('/calendar', cal_base(['visibility' => 'role', 'role_ids' => [$role->id]]))->assertCreated();
    Notification::assertNothingSent();

    $this->actingAs($creator)->postJson('/calendar', cal_base(['visibility' => 'role', 'role_ids' => [$role->id], 'track_attendance' => true]))->assertCreated();
    Notification::assertSentToTimes($member, InboxNotification::class, 1);
    Notification::assertNotSentTo($inactive, InboxNotification::class);
});

it('notifies nobody for private and all events without invitations', function () {
    $creator = cal_user(['calendar.view', 'calendar.create']);
    $guest = cal_user();

    $this->actingAs($creator)->postJson('/calendar', cal_base(['visibility' => 'private', 'attendee_ids' => [$guest->personnel_id]]))->assertCreated();
    $this->actingAs($creator)->postJson('/calendar', cal_base(['visibility' => 'all']))->assertCreated();
    Notification::assertNothingSent();
});

it('writes the date only for all-day events', function () {
    $creator = cal_user(['calendar.view', 'calendar.create']);
    $guest = cal_user();

    $this->actingAs($creator)->postJson('/calendar', cal_base(['all_day' => true, 'starts_at' => '2026-11-10', 'ends_at' => '2026-11-12', 'location' => null,
        'visibility' => 'attendees', 'attendee_ids' => [$guest->personnel_id]]))->assertCreated();

    Notification::assertSentTo($guest, InboxNotification::class, fn (InboxNotification $n) => $n->toArray($guest)['message'] === '10.11.2026 – 12.11.2026');
});

it('sends the created notice to whoever becomes a recipient on update, nothing more to the others', function () {
    $creator = cal_user(['calendar.view', 'calendar.create']);
    $old = cal_user();
    $new = cal_user();
    $payload = cal_base(['visibility' => 'attendees', 'attendee_ids' => [$old->personnel_id]]);
    $id = $this->actingAs($creator)->postJson('/calendar', $payload)->json('id');

    $this->actingAs($creator)->putJson("/calendar/{$id}", ['attendee_ids' => [$old->personnel_id, $new->personnel_id]] + $payload)->assertOk();
    Notification::assertSentToTimes($old, InboxNotification::class, 1);
    Notification::assertSentTo($new, InboxNotification::class, fn (InboxNotification $n) => $n->toArray($new)['title'] === 'Termin angelegt: Dienst');
    Notification::assertNotSentTo($creator, InboxNotification::class);

    $this->actingAs($creator)->putJson("/calendar/{$id}", ['title' => 'Dienst 2', 'attendee_ids' => [$old->personnel_id, $new->personnel_id]] + $payload)->assertOk();
    Notification::assertSentTo($new, InboxNotification::class, fn (InboxNotification $n) => $n->toArray($new)['title'] === 'Termin geändert: Dienst 2');
    Notification::assertSentToTimes($new, InboxNotification::class, 2);
    Notification::assertSentToTimes($old, InboxNotification::class, 2);
});

it('sends the created notice when the visibility widens to new recipients', function () {
    $creator = cal_user(['calendar.view', 'calendar.create']);
    $guest = cal_user();
    $role = cal_role();
    $member = cal_user(['calendar.view'], false, [$role]);
    $id = $this->actingAs($creator)->postJson('/calendar', cal_base(['visibility' => 'private', 'attendee_ids' => [$guest->personnel_id]]))->json('id');
    Notification::assertNothingSent();

    $this->actingAs($creator)->putJson("/calendar/{$id}", cal_base(['visibility' => 'attendees', 'attendee_ids' => [$guest->personnel_id]]))->assertOk();
    Notification::assertSentToTimes($guest, InboxNotification::class, 1);

    $this->actingAs($creator)->putJson("/calendar/{$id}", cal_base(['visibility' => 'role', 'role_ids' => [$role->id]]))->assertOk();
    Notification::assertNotSentTo($member, InboxNotification::class);
    $this->actingAs($creator)->putJson("/calendar/{$id}", cal_base(['visibility' => 'role', 'role_ids' => [$role->id], 'track_attendance' => true]))->assertOk();
    Notification::assertSentTo($member, InboxNotification::class, fn (InboxNotification $n) => $n->toArray($member)['title'] === 'Termin angelegt: Dienst');
    Notification::assertNotSentTo($creator, InboxNotification::class);
});

it('never fails the request when a notification fails', function () {
    Notification::shouldReceive('send')->andThrow(new RuntimeException('mail down'));
    $creator = cal_user(['calendar.view', 'calendar.create']);

    $this->actingAs($creator)->postJson('/calendar', cal_base(['visibility' => 'attendees', 'attendee_ids' => [cal_user()->personnel_id]]))->assertCreated();
});
