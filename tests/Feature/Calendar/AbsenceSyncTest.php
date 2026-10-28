<?php

use App\Models\Personnel;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;
use Modules\Calendar\Models\CalendarAttendee;
use Modules\Calendar\Models\CalendarEvent;
use Modules\Calendar\Support\AbsenceSync;
use Modules\Calendar\Support\IcalToken;
use Modules\Forms\Events\FormDecided;
use Modules\Forms\Events\FormDeleted;
use Modules\Forms\Models\FormField;
use Modules\Forms\Models\FormSubmission;

require_once __DIR__.'/calendarHelpers.php';
require_once __DIR__.'/../Forms/formsHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->editor = fs_user(['application.edit', 'calendar.view', 'calendar.create'], Personnel::factory()->create());
    $this->record = fs_record();
    $this->type = fs_type([], ['is_absence' => true]);
});

function ab_answers(?string $von, ?string $bis, string $reason = 'Geheimer Grund XYZ'): array
{
    $a = [['key' => 'grund', 'label' => 'Grund', 'type' => 'textarea', 'width' => 'full', 'value' => $reason]];
    foreach (['von_datum' => $von, 'bis_datum' => $bis] as $k => $v) {
        if ($v !== null) {
            $a[] = ['key' => $k, 'label' => $k, 'type' => 'date', 'width' => 'half', 'value' => $v];
        }
    }

    return $a;
}

function ab_sub(array $over = []): FormSubmission
{
    return fs_submission(test()->type, test()->record, $over + ['answers' => ab_answers('2026-11-10', '2026-11-12')]);
}

function ab_decide(FormSubmission $s, string $status): FormSubmission
{
    $old = $s->status;
    $s->forceFill(['status' => $status, 'decided_by' => test()->editor->id])->save();
    FormDecided::dispatch($s, $old);

    return $s;
}

it('mirrors an accepted absence with exactly the specified fields and no content', function () {
    $s = ab_decide(ab_sub(), 'accepted');

    $e = CalendarEvent::sole();
    expect($e->form_submission_id)->toBe($s->id)->and($e->title)->toBe('Abwesend: Max Muster')
        ->and($e->description)->toBeNull()->and($e->location)->toBeNull()
        ->and($e->all_day)->toBeTrue()->and($e->starts_at->format('Y-m-d H:i:s'))->toBe('2026-11-10 00:00:00')
        ->and($e->ends_at->format('Y-m-d H:i:s'))->toBe('2026-11-12 00:00:00')
        ->and($e->color)->toBe('gray')->and($e->category)->toBe('absence')->and($e->visibility)->toBe('all')
        ->and($e->track_attendance)->toBeFalse()->and($e->created_by)->toBe($this->editor->id)
        ->and($e->recurrence_rule)->toBeNull()->and($e->isLocked())->toBeTrue();
    $a = CalendarAttendee::sole();
    expect($a->personnel_id)->toBe($this->record->id)->and($a->is_organizer)->toBeTrue()->and($a->response)->toBe('accepted');
});

it('removes the mirror for every other status and mirrors again on accept', function (string $status) {
    $s = ab_decide(ab_sub(), 'accepted');
    expect(CalendarEvent::count())->toBe(1);
    ab_decide($s, $status);
    expect(CalendarEvent::count())->toBe(0);
    ab_decide($s, 'accepted');
    expect(CalendarEvent::count())->toBe(1);
})->with(['rejected', 'deferred', 'new', 'in_progress']);

it('keeps one event when deciding twice or on note-only changes, and follows changed answers', function () {
    $s = ab_decide(ab_sub(), 'accepted');
    FormDecided::dispatch($s, 'accepted');
    ab_decide($s, 'accepted');
    expect(CalendarEvent::count())->toBe(1)->and(CalendarAttendee::count())->toBe(1);

    $s->forceFill(['answers' => ab_answers('2026-12-01', '2026-12-02')])->save();
    expect(AbsenceSync::sync($s))->toBe(AbsenceSync::UPDATED)
        ->and(CalendarEvent::sole()->starts_at->toDateString())->toBe('2026-12-01');
});

it('creates nothing for missing, reversed or garbage dates and removes an existing mirror with a content-free warning', function (?string $von, ?string $bis) {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logged) {
        $logged[] = $m->message.json_encode($m->context);
    });
    $s = ab_decide(ab_sub(), 'accepted');
    expect(CalendarEvent::count())->toBe(1);
    $s->forceFill(['answers' => ab_answers($von, $bis)])->save();
    expect(AbsenceSync::sync($s))->toBe(AbsenceSync::SKIPPED_INVALID)->and(CalendarEvent::count())->toBe(0);
    expect(implode('', $logged))->toContain('Absence mirror skipped')->not->toContain('Geheimer')->not->toContain('2026');
})->with([[null, '2026-11-12'], ['2026-11-10', null], [null, null], ['2026-11-13', '2026-11-12'], ['garbage', '2026-11-12'],
    ['2026-02-30', '2026-03-05'], ['31.02.2026', '2026-03-05'], ['', '']]);

it('never creates an event for invalid dates', function () {
    ab_decide(ab_sub(['answers' => ab_answers('2026-11-13', '2026-11-12')]), 'accepted');
    expect(CalendarEvent::count())->toBe(0);
});

it('accepts d.m.Y and a one-day range', function () {
    ab_decide(ab_sub(['answers' => ab_answers('5.11.2026', '05.11.2026')]), 'accepted');
    $e = CalendarEvent::sole();
    expect($e->starts_at->toDateString())->toBe('2026-11-05')->and($e->ends_at->toDateString())->toBe('2026-11-05');
});

it('reads an ISO date with a time part (imported legacy answers keep raw strings)', function (string $von, string $bis) {
    ab_decide(ab_sub(['answers' => ab_answers($von, $bis)]), 'accepted');
    $e = CalendarEvent::sole();
    expect($e->starts_at->toDateString())->toBe('2026-11-10')->and($e->ends_at->toDateString())->toBe('2026-11-12');
})->with([['2026-11-10T00:00', '2026-11-12T23:59'], ['2026-11-10 00:00:00', '2026-11-12 08:30:15'], [' 2026-11-10  00:00 ', "2026-11-12\t00:00"]]);

it('still refuses two-digit years, m/d/Y and broken time parts', function (string $von) {
    ab_decide(ab_sub(['answers' => ab_answers($von, '2026-11-12')]), 'accepted');
    expect(CalendarEvent::count())->toBe(0);
})->with(['10.11.26', '11/10/2026', '2026-11-10T', '2026-11-10 25:00', '2026-11-10T00:00x', '26-11-10']);

it('ignores types without is_absence', function () {
    $type = fs_type([], ['is_absence' => false]);
    ab_decide(fs_submission($type, $this->record, ['answers' => ab_answers('2026-11-10', '2026-11-12')]), 'accepted');
    expect(CalendarEvent::count())->toBe(0);
});

it('works without a record: no attendee, no name suffix', function () {
    ab_decide(fs_submission($this->type, null, ['applicant_name' => 'Gast', 'answers' => ab_answers('2026-11-10', '2026-11-12')]), 'accepted');
    expect(CalendarEvent::sole()->title)->toBe('Abwesend: Gast')->and(CalendarAttendee::count())->toBe(0);
});

it('strips only a trailing service number group from the name', function (string $in, string $out) {
    expect(AbsenceSync::name($in))->toBe($out);
})->with([['Max Muster (BF-0001)', 'Max Muster'], ['Max (Mäx) Muster (BF-1)', 'Max (Mäx) Muster'], ['Max Muster', 'Max Muster'], ['(BF-1)', '(BF-1)']]);

it('is inert while forms is off, and a switched off calendar does nothing', function () {
    app(Settings::class)->set('modules.forms.enabled', false);
    ab_decide(ab_sub(), 'accepted');
    expect(CalendarEvent::count())->toBe(0);
    app(Settings::class)->set('modules.forms.enabled', true);
    app(Settings::class)->set('modules.calendar.enabled', false);
    ab_decide(ab_sub(), 'accepted');
    expect(CalendarEvent::count())->toBe(0);
});

it('removes the event on delete, cascade included, and survives an already gone event', function () {
    $s = ab_decide(ab_sub(), 'accepted');
    $s->delete();
    expect(CalendarEvent::count())->toBe(0)->and(CalendarAttendee::count())->toBe(0);

    $s2 = ab_decide(ab_sub(), 'accepted');
    CalendarEvent::query()->delete();
    $s2->delete();
    expect(CalendarEvent::count())->toBe(0);
});

it('removes via FormDeleted alone (no cascade)', function () {
    $s = ab_decide(ab_sub(), 'accepted');
    FormDeleted::dispatch($s->id, $s->form_type_id);
    expect(CalendarEvent::count())->toBe(0);
});

it('notifies nobody about a mirror', function () {
    $applicant = fs_user([], $this->record);
    ab_decide(ab_sub(), 'accepted');
    expect(DatabaseNotification::query()->where('type', 'calendar')->count())->toBe(0)
        ->and($applicant->notifications()->count())->toBe(0);
});

it('locks the mirror for creator, manager and admin through HTTP', function () {
    ab_decide(ab_sub(), 'accepted');
    $e = CalendarEvent::sole();
    $body = ['title' => 'X', 'starts_at' => '2026-11-10', 'ends_at' => '2026-11-10', 'all_day' => true, 'visibility' => 'all'];
    foreach ([$this->editor, cal_user(['calendar.manage']), cal_user(['admin'])] as $user) {
        $this->actingAs($user)->putJson("/calendar/{$e->id}", $body)->assertForbidden();
        $this->actingAs($user)->deleteJson("/calendar/{$e->id}")->assertForbidden();
    }
    expect($e->fresh()->title)->toBe('Abwesend: Max Muster');
});

it('keeps description and answers out of the row, feed, detail and ics', function () {
    ab_decide(ab_sub(), 'accepted');
    $viewer = cal_user(['calendar.view']);
    $e = CalendarEvent::sole();

    expect(json_encode($e->getAttributes()))->not->toContain('Geheimer');
    $feed = $this->actingAs($viewer)->getJson('/calendar/events?from=2026-11-01&to=2026-12-01')->assertOk();
    expect($feed->getContent())->not->toContain('Geheimer')->and($feed->json())->toHaveCount(1);
    $detail = $this->actingAs($viewer)->getJson("/calendar/{$e->id}")->assertOk();
    expect($detail->getContent())->not->toContain('Geheimer')->and($detail->json('description'))->toBeNull();
    $ics = $this->get('/api/calendar/ical/'.IcalToken::issue($viewer))->assertOk()->getContent();
    expect($ics)->toContain('Abwesend: Max Muster')->not->toContain('Geheimer');
});

it('end to end: submit, accept via HTTP shows in the feed without description, reject removes it', function () {
    $applicant = fs_user([], $this->record);
    foreach ([['von_datum', 'date'], ['bis_datum', 'date'], ['grund', 'textarea']] as $i => [$k, $t]) {
        (new FormField)->forceFill(['form_type_id' => $this->type->id, 'key' => $k, 'label' => $k, 'type' => $t, 'sort_order' => $i + 1, 'required' => $k !== 'grund'])->save();
    }
    $this->actingAs($applicant)->post("/forms/new/{$this->type->id}", ['answers' => ['von_datum' => '2026-11-10', 'bis_datum' => '2026-11-12', 'grund' => 'Geheimer Grund XYZ']])->assertRedirect();
    $s = FormSubmission::sole();
    expect(CalendarEvent::count())->toBe(0);

    $viewer = cal_user(['calendar.view']);
    $this->actingAs($this->editor)->put("/forms/admin/{$s->public_id}", ['status' => 'accepted', 'decision_note' => ''])->assertRedirect();
    $feed = $this->actingAs($viewer)->getJson('/calendar/events?from=2026-11-01&to=2026-12-01')->assertOk();
    expect($feed->json())->toHaveCount(1)->and($feed->json('0.title'))->toBe('Abwesend: Max Muster')
        ->and($feed->getContent())->not->toContain('Geheimer')
        ->and(CalendarEvent::sole()->created_by)->toBe($this->editor->id);

    $this->actingAs($this->editor)->put("/forms/admin/{$s->public_id}", ['status' => 'rejected', 'decision_note' => ''])->assertRedirect();
    expect($this->actingAs($viewer)->getJson('/calendar/events?from=2026-11-01&to=2026-12-01')->json())->toBe([]);
});
