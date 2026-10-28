<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calendar\Models\CalendarAttendee;

require_once __DIR__.'/calendarHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('lets an invited record answer and stores the time', function () {
    $user = cal_user();
    $e = cal_event(['visibility' => 'attendees'], [], [$user->personnel_id => 'pending']);

    $this->actingAs($user)->postJson("/calendar/{$e->id}/response", ['response' => 'declined'])->assertOk()->assertJson(['response' => 'declined']);
    $row = CalendarAttendee::where('event_id', $e->id)->first();
    expect($row->response)->toBe('declined')->and($row->responded_at)->not->toBeNull();
    expect($this->actingAs($user)->getJson("/calendar/{$e->id}")->json('my_response'))->toBe('declined');
});

it('validates the answer', function (mixed $response) {
    $user = cal_user();
    $e = cal_event(['visibility' => 'attendees'], [], [$user->personnel_id => 'pending']);

    $this->actingAs($user)->postJson("/calendar/{$e->id}/response", ['response' => $response])->assertUnprocessable();
})->with(['pending', 'yes', null, [['accepted']]]);

it('creates the row on the first answer of a role member when attendance is tracked', function () {
    $role = cal_role();
    $member = cal_user(['calendar.view'], true, [$role]);
    $tracked = cal_event(['visibility' => 'role', 'track_attendance' => true], [$role]);
    $untracked = cal_event(['visibility' => 'role', 'track_attendance' => false], [$role]);

    expect($this->actingAs($member)->getJson("/calendar/{$tracked->id}")->json('can_respond'))->toBeTrue();
    $this->actingAs($member)->postJson("/calendar/{$tracked->id}/response", ['response' => 'tentative'])->assertOk();
    expect(CalendarAttendee::where('event_id', $tracked->id)->where('personnel_id', $member->personnel_id)->value('response'))->toBe('tentative');

    expect($this->actingAs($member)->getJson("/calendar/{$untracked->id}")->json('can_respond'))->toBeFalse();
    $this->actingAs($member)->postJson("/calendar/{$untracked->id}/response", ['response' => 'accepted'])->assertForbidden();
    expect(CalendarAttendee::where('event_id', $untracked->id)->exists())->toBeFalse();
});

it('refuses everyone else', function () {
    $e = cal_event(['visibility' => 'all']);
    $hidden = cal_event(['visibility' => 'private']);

    $this->actingAs(cal_user())->postJson("/calendar/{$e->id}/response", ['response' => 'accepted'])->assertForbidden();
    $this->actingAs(cal_user([], true))->postJson("/calendar/{$e->id}/response", ['response' => 'accepted'])->assertNotFound();
    $this->actingAs(cal_user(['calendar.view'], false))->postJson("/calendar/{$e->id}/response", ['response' => 'accepted'])->assertForbidden();
    $this->actingAs(cal_user())->postJson("/calendar/{$hidden->id}/response", ['response' => 'accepted'])->assertNotFound();
    $this->actingAs(cal_user())->postJson('/calendar/999999/response', ['response' => 'accepted'])->assertNotFound();
    expect(CalendarAttendee::count())->toBe(0);
});

it('shows the attendee list only where the legacy did', function () {
    $role = cal_role();
    $member = cal_user(['calendar.view'], true, [$role]);
    $creator = cal_user(['calendar.view', 'calendar.create']);

    $attendees = cal_event(['visibility' => 'attendees', 'created_by' => $creator->id], [], [$member->personnel_id => 'accepted']);
    $all = cal_event(['visibility' => 'all', 'created_by' => $creator->id], [], [$member->personnel_id => 'accepted']);
    $tracked = cal_event(['visibility' => 'role', 'track_attendance' => true, 'created_by' => $creator->id], [$role]);
    $untracked = cal_event(['visibility' => 'role', 'created_by' => $creator->id], [$role]);

    expect($this->actingAs($member)->getJson("/calendar/{$attendees->id}")->json('attendees.0'))->toMatchArray(['personnel_id' => $member->personnel_id, 'response' => 'accepted'])
        ->and($this->actingAs($creator)->getJson("/calendar/{$all->id}")->json('attendees'))->toBeNull()
        ->and($this->actingAs($member)->getJson("/calendar/{$tracked->id}")->json('attendees'))->toBe([
            ['personnel_id' => $member->personnel_id, 'name' => $member->personnel->full_name, 'response' => null, 'is_organizer' => false],
        ])
        ->and($this->actingAs($member)->getJson("/calendar/{$untracked->id}")->json('attendees'))->toBeNull();
});

it('answers idempotently when two first answers race', function () {
    $role = cal_role();
    $member = cal_user(['calendar.view'], true, [$role]);
    $e = cal_event(['visibility' => 'role', 'track_attendance' => true], [$role]);
    CalendarAttendee::creating(function (CalendarAttendee $a) {
        CalendarAttendee::flushEventListeners();
        (new CalendarAttendee)->forceFill(['event_id' => $a->event_id, 'personnel_id' => $a->personnel_id, 'response' => 'declined'])->save();
    });

    $this->actingAs($member)->postJson("/calendar/{$e->id}/response", ['response' => 'accepted'])->assertOk()->assertJson(['response' => 'accepted']);
    expect(CalendarAttendee::where('event_id', $e->id)->pluck('response')->all())->toBe(['accepted']);
});

it('refuses an answer on a locked absence mirror, also for its applicant', function () {
    $user = cal_user(['calendar.view', 'calendar.create']);
    $e = cal_locked($user);
    (new CalendarAttendee)->forceFill(['event_id' => $e->id, 'personnel_id' => $user->personnel_id, 'response' => 'accepted', 'is_organizer' => true])->save();

    $this->actingAs($user)->postJson("/calendar/{$e->id}/response", ['response' => 'declined'])->assertForbidden();
    expect(CalendarAttendee::where('event_id', $e->id)->value('response'))->toBe('accepted');
});
