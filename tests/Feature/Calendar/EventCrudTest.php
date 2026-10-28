<?php

use App\Models\Personnel;
use App\Models\Rank;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calendar\Models\CalendarAttendee;
use Modules\Calendar\Models\CalendarEvent;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/calendarHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function cal_payload(array $over = []): array
{
    return $over + ['title' => 'Übung', 'starts_at' => '2026-11-10T18:00', 'ends_at' => '2026-11-10T20:00', 'visibility' => 'all'];
}

it('creates an event as UTC with the creator as accepted organizer and audits it', function () {
    $user = cal_user(['calendar.view', 'calendar.create']);
    $guest = Personnel::factory()->create();

    $res = $this->actingAs($user)->postJson('/calendar', cal_payload([
        'visibility' => 'attendees', 'attendee_ids' => [$guest->id], 'description' => '<b>Klartext</b>', 'location' => 'Wache 1',
        'created_by' => 999, 'form_submission_id' => 5,
    ]))->assertCreated();

    $e = CalendarEvent::findOrFail($res->json('id'));
    expect($e->starts_at->format('Y-m-d H:i'))->toBe('2026-11-10 17:00')
        ->and($e->created_by)->toBe($user->id)->and($e->form_submission_id)->toBeNull()
        ->and($e->description)->toBe('<b>Klartext</b>')->and($e->color)->toBe('accent')->and($e->category)->toBe('general');
    $rows = CalendarAttendee::where('event_id', $e->id)->get()->keyBy('personnel_id');
    expect($rows[$user->personnel_id]->only(['response', 'is_organizer']))->toBe(['response' => 'accepted', 'is_organizer' => true])
        ->and($rows[$user->personnel_id]->responded_at)->not->toBeNull()
        ->and($rows[$guest->id]->only(['response', 'is_organizer']))->toBe(['response' => 'pending', 'is_organizer' => false]);
    $log = Activity::latest('id')->first();
    expect($log->description)->toBe('Termin erstellt')->and($log->log_name)->toBe('Kalender')->and($log->details)->toBe("ID: {$e->id}, Titel: Übung");
});

it('accepts explicit offsets and stores all-day events as dates', function () {
    $user = cal_user(['calendar.view', 'calendar.create']);

    $id = $this->actingAs($user)->postJson('/calendar', cal_payload(['starts_at' => '2026-07-01T10:00:00Z', 'ends_at' => '2026-07-01T12:00:00+02:00']))->json('id');
    expect(CalendarEvent::find($id)->only(['starts_at', 'ends_at']))->sequence(
        fn ($v) => $v->format('Y-m-d H:i')->toBe('2026-07-01 10:00'),
        fn ($v) => $v->format('Y-m-d H:i')->toBe('2026-07-01 10:00'),
    );

    $id = $this->actingAs($user)->postJson('/calendar', cal_payload(['all_day' => true, 'starts_at' => '2026-12-31', 'ends_at' => '2027-01-01']))->json('id');
    $raw = DB::table('calendar_events')->find($id);
    expect([substr($raw->starts_at, 0, 19), substr($raw->ends_at, 0, 19)])->toBe(['2026-12-31 00:00:00', '2027-01-01 00:00:00']);

    $this->actingAs($user)->getJson('/calendar/events?from=2026-12-01&to=2027-02-01')->assertOk()
        ->assertJsonFragment(['id' => $id, 'occurrence_start' => '2026-12-31', 'occurrence_end' => '2027-01-01', 'all_day' => true]);
});

it('normalises the series rule and moves UNTIL into its column', function () {
    $user = cal_user(['calendar.view', 'calendar.create']);

    $id = $this->actingAs($user)->postJson('/calendar', cal_payload(['recurrence_rule' => 'freq=weekly;interval=2;byday=we,tu;until=2026-12-31']))->assertCreated()->json('id');
    $e = CalendarEvent::find($id);
    expect($e->recurrence_rule)->toBe('FREQ=WEEKLY;INTERVAL=2;BYDAY=TU,WE')
        ->and($e->recurrence_until->format('Y-m-d H:i:s'))->toBe('2026-12-31 22:59:59');

    $id = $this->actingAs($user)->postJson('/calendar', cal_payload(['recurrence_rule' => 'FREQ=DAILY;COUNT=3']))->json('id');
    expect(CalendarEvent::find($id)->only(['recurrence_rule', 'recurrence_until']))->toBe(['recurrence_rule' => 'FREQ=DAILY;INTERVAL=1;COUNT=3', 'recurrence_until' => null]);

    $feed = $this->actingAs($user)->getJson('/calendar/events?from=2026-11-01&to=2026-12-01')->json();
    expect(collect($feed)->where('id', $id)->pluck('occurrence_start')->all())->toBe(['2026-11-10T17:00:00Z', '2026-11-11T17:00:00Z', '2026-11-12T17:00:00Z'])
        ->and(collect($feed)->firstWhere('id', $id)['recurring'])->toBeTrue();
});

it('validates the input', function () {
    foreach ([
        'title missing' => [['title' => '  '], 'title'],
        'title too long' => [['title' => str_repeat('a', 161)], 'title'],
        'title not a string' => [['title' => ['x']], 'title'],
        'description too long' => [['description' => str_repeat('a', 2001)], 'description'],
        'location too long' => [['location' => str_repeat('a', 256)], 'location'],
        'end before start' => [['ends_at' => '2026-11-10T17:59'], 'ends_at'],
        'bad start' => [['starts_at' => '10.11.2026 18:00'], 'starts_at'],
        'impossible date' => [['starts_at' => '2026-02-30T18:00'], 'starts_at'],
        'all-day needs dates' => [['all_day' => true], 'starts_at'],
        'bad visibility' => [['visibility' => 'public'], 'visibility'],
        'bad color' => [['color' => 'orange'], 'color'],
        'bad category' => [['category' => 'dienst'], 'category'],
        'role needs roles' => [['visibility' => 'role'], 'role_ids'],
        'unknown role' => [['visibility' => 'role', 'role_ids' => [99999]], 'role_ids.0'],
        'unknown attendee' => [['visibility' => 'attendees', 'attendee_ids' => [99999]], 'attendee_ids.0'],
        'rrule yearly' => [['recurrence_rule' => 'FREQ=YEARLY'], 'recurrence_rule'],
        'rrule interval 0' => [['recurrence_rule' => 'FREQ=DAILY;INTERVAL=0'], 'recurrence_rule'],
        'rrule count and until' => [['recurrence_rule' => 'FREQ=DAILY;COUNT=2;UNTIL=2026-12-01'], 'recurrence_rule'],
        'byday only weekly' => [['recurrence_rule' => 'FREQ=DAILY;BYDAY=MO'], 'recurrence_rule'],
        'count plus until field' => [['recurrence_rule' => 'FREQ=DAILY;COUNT=2', 'recurrence_until' => '2026-12-01'], 'recurrence_until'],
        'until before start' => [['recurrence_rule' => 'FREQ=DAILY', 'recurrence_until' => '2026-11-01'], 'recurrence_until'],
        'rule UNTIL month 13' => [['recurrence_rule' => 'FREQ=DAILY;UNTIL=20261345'], 'recurrence_rule'],
        'rule UNTIL Feb 30' => [['recurrence_rule' => 'FREQ=DAILY;UNTIL=2026-02-30'], 'recurrence_rule'],
        'rule UNTIL 2026-13-45' => [['recurrence_rule' => 'FREQ=DAILY;UNTIL=2026-13-45'], 'recurrence_rule'],
        'rule UNTIL hour 25' => [['recurrence_rule' => 'FREQ=DAILY;UNTIL=20261231T250000Z'], 'recurrence_rule'],
        'series longer than 31 days' => [['recurrence_rule' => 'FREQ=MONTHLY', 'all_day' => true, 'starts_at' => '2026-11-01', 'ends_at' => '2026-12-02'], 'ends_at'],
        'timed series longer than 31 days' => [['recurrence_rule' => 'FREQ=WEEKLY', 'ends_at' => '2026-12-11T18:01'], 'ends_at'],
        'start not on a BYDAY' => [['recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO,WE'], 'recurrence_rule'],
        'archived attendee' => [['visibility' => 'attendees', 'attendee_ids' => ['archived']], 'attendee_ids.0'],
    ] as [$over, $field]) {
        if (($over['attendee_ids'][0] ?? null) === 'archived') {
            $over['attendee_ids'] = [Personnel::factory()->create(['rank_id' => Rank::factory()->create(['is_archive' => true])->id])->id];
        }
        $this->actingAs(cal_user(['calendar.view', 'calendar.create']))->postJson('/calendar', cal_payload($over))->assertUnprocessable()->assertJsonValidationErrors($field);
        expect(CalendarEvent::count())->toBe(0);
    }
});

it('needs calendar.create to create and creator or manage to change', function () {
    $creator = cal_user(['calendar.view', 'calendar.create']);
    $e = cal_event(['created_by' => $creator->id]);

    $this->actingAs(cal_user(['calendar.view']))->postJson('/calendar', cal_payload())->assertForbidden();
    $this->actingAs(cal_user(['calendar.view', 'calendar.create']))->putJson("/calendar/{$e->id}", cal_payload())->assertForbidden();
    $this->actingAs(cal_user(['calendar.view', 'calendar.create']))->deleteJson("/calendar/{$e->id}")->assertForbidden();
    $this->actingAs(cal_user(['admin']))->postJson('/calendar', cal_payload())->assertCreated();

    $this->actingAs($creator)->putJson("/calendar/{$e->id}", cal_payload(['title' => 'Neu']))->assertOk();
    $this->actingAs(cal_user(['calendar.manage']))->putJson("/calendar/{$e->id}", cal_payload(['title' => 'Manager']))->assertOk();
    expect($e->fresh()->title)->toBe('Manager')->and($e->fresh()->created_by)->toBe($creator->id);
    expect(Activity::where('description', 'Termin bearbeitet')->count())->toBe(2);

    $this->actingAs(cal_user(['calendar.manage']))->deleteJson("/calendar/{$e->id}")->assertNoContent();
    expect(CalendarEvent::find($e->id))->toBeNull()->and(Activity::where('description', 'Termin gelöscht')->where('log_name', 'Kalender')->exists())->toBeTrue();
});

it('never lets anyone edit or delete a mirrored absence', function () {
    $creator = cal_user(['calendar.view', 'calendar.create']);
    $e = cal_locked($creator);

    foreach ([$creator, cal_user(['calendar.manage']), cal_user(['admin']), User::factory()->superAdmin()->create()] as $user) {
        $this->actingAs($user)->putJson("/calendar/{$e->id}", cal_payload())->assertForbidden();
        $this->actingAs($user)->deleteJson("/calendar/{$e->id}")->assertForbidden();
        $feed = collect($this->actingAs($user)->getJson('/calendar/events?from=2026-11-01&to=2026-12-01')->json())->firstWhere('id', $e->id);
        expect($feed['can_edit'])->toBeFalse()->and($feed['source_locked'])->toBeTrue();
        expect($this->actingAs($user)->getJson("/calendar/{$e->id}")->json('can_edit'))->toBeFalse();
    }
    expect($e->fresh())->not->toBeNull();
});

it('syncs invitations on update and keeps the organizer', function () {
    $creator = cal_user(['calendar.view', 'calendar.create']);
    [$a, $b] = Personnel::factory()->count(2)->create();
    $id = $this->actingAs($creator)->postJson('/calendar', cal_payload(['visibility' => 'attendees', 'attendee_ids' => [$a->id]]))->json('id');

    $this->actingAs($creator)->putJson("/calendar/{$id}", cal_payload(['visibility' => 'attendees', 'attendee_ids' => [$b->id]]))->assertOk();
    expect(CalendarAttendee::where('event_id', $id)->pluck('personnel_id')->sort()->values()->all())->toBe(collect([$creator->personnel_id, $b->id])->sort()->values()->all());

    $this->actingAs($creator)->putJson("/calendar/{$id}", cal_payload(['visibility' => 'all', 'attendee_ids' => [$a->id]]))->assertOk();
    expect(CalendarAttendee::where('event_id', $id)->pluck('personnel_id')->all())->toBe([$creator->personnel_id]);
});

it('returns a conflict hint without blocking, only with events the creator may see', function () {
    $creator = cal_user(['calendar.view', 'calendar.create']);
    $busy = Personnel::factory()->create();
    $free = Personnel::factory()->create();
    cal_event(['title' => 'Sichtbar', 'starts_at' => '2026-11-10 17:30:00', 'ends_at' => '2026-11-10 18:30:00'], [], [$busy->id => 'accepted']);
    cal_event(['title' => 'Geheim', 'visibility' => 'private', 'starts_at' => '2026-11-10 17:30:00', 'ends_at' => '2026-11-10 18:30:00'], [], [$busy->id => 'accepted']);
    cal_event(['title' => 'Abgesagt', 'starts_at' => '2026-11-10 17:30:00', 'ends_at' => '2026-11-10 18:30:00'], [], [$busy->id => 'declined']);
    cal_event(['title' => 'Später', 'starts_at' => '2026-11-10 20:00:00', 'ends_at' => '2026-11-10 21:00:00'], [], [$busy->id => 'accepted']);
    cal_event(['title' => 'Serie', 'starts_at' => '2026-10-13 16:00:00', 'ends_at' => '2026-10-13 17:30:00', 'recurrence_rule' => 'FREQ=WEEKLY'], [], [$busy->id => 'pending']);

    DB::enableQueryLog();
    $res = $this->actingAs($creator)->postJson('/calendar', cal_payload(['visibility' => 'attendees', 'attendee_ids' => [$busy->id, $free->id]]))->assertCreated();

    expect($res->json('conflicts'))->toHaveCount(1)
        ->and($res->json('conflicts.0.personnel_id'))->toBe($busy->id)
        ->and(collect($res->json('conflicts.0.events'))->pluck('title')->sort()->values()->all())->toBe(['Serie', 'Sichtbar'])
        ->and(json_encode($res->json()))->not->toContain('Geheim');
    expect(collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'inner join "calendar_attendees" as "a"'))->count())->toBe(1);
});

it('looks personnel up for calendar.create only, at most 20 by name', function () {
    Personnel::factory()->count(25)->create();
    Personnel::factory()->create(['full_name' => 'Zora 100%']);

    $this->actingAs(cal_user(['calendar.view']))->getJson('/calendar/personnel?q=a')->assertForbidden();
    $user = cal_user(['calendar.view', 'calendar.create']);
    expect($this->actingAs($user)->getJson('/calendar/personnel')->assertOk()->json())->toHaveCount(20)
        ->and($this->actingAs($user)->getJson('/calendar/personnel?q=100%25')->json())->toBe([['id' => Personnel::where('full_name', 'Zora 100%')->value('id'), 'name' => 'Zora 100%']])
        ->and($this->actingAs($user)->getJson('/calendar/personnel?q[]=x')->assertOk()->json())->toHaveCount(20);
});

it('validates the feed window', function (string $query) {
    $this->actingAs(cal_user())->getJson('/calendar/events?'.$query)->assertUnprocessable();
})->with(['from=2026-11-01', 'from=x&to=y', 'from=2026-11-01&to=2026-11-01', 'from=2026-11-01&to=2028-01-01', 'from[]=1&to=2026-12-01']);

it('lists the feed items with the documented fields', function () {
    $user = cal_user(['calendar.view', 'calendar.create']);
    $e = cal_event(['created_by' => $user->id, 'location' => 'Wache', 'visibility' => 'attendees', 'description' => 'geheim'], [], [$user->personnel_id => 'tentative']);

    $item = $this->actingAs($user)->getJson('/calendar/events?from=2026-11-01T00:00:00%2B01:00&to=2026-12-01')->assertOk()->json('0');
    expect($item)->toBe([
        'occurrence_start' => '2026-11-10T08:00:00Z', 'occurrence_end' => '2026-11-10T10:00:00Z',
        'id' => $e->id, 'all_day' => false, 'title' => $e->title, 'color' => 'accent', 'category' => 'general', 'location' => 'Wache',
        'attendee_count' => 1, 'my_response' => 'tentative', 'can_edit' => true, 'recurring' => false, 'source_locked' => false, 'visibility' => 'attendees',
    ]);
});

it('keeps the feed at a constant number of queries', function () {
    $user = cal_user(['calendar.view'], true, [$r1 = cal_role(), $r2 = cal_role()]);
    $members = User::factory()->count(3)->create(['personnel_id' => fn () => Personnel::factory()->create()->id]);
    $members->each(fn (User $u) => $u->assignRole($r1));
    $seed = function (int $n) use ($user, $r1, $r2) {
        for ($i = 0; $i < $n; $i++) {
            $vis = ['all', 'attendees', 'role'][$i % 3];
            cal_event(['visibility' => $vis, 'recurrence_rule' => $i % 2 ? 'FREQ=DAILY' : null, 'starts_at' => '2026-11-0'.($i % 9 + 1).' 08:00:00', 'ends_at' => '2026-11-0'.($i % 9 + 1).' 09:00:00'],
                $vis === 'role' ? [$r1, $r2] : [], [$user->personnel_id => 'accepted']);
        }
    };
    $count = function () use ($user) {
        $this->actingAs($user)->getJson('/calendar/events?from=2026-11-01&to=2026-12-01')->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $items = $this->actingAs($user)->getJson('/calendar/events?from=2026-11-01&to=2026-12-01')->assertOk()->json();
        DB::disableQueryLog();

        return [count(DB::getQueryLog()), $items];
    };

    $seed(3);
    [$few, $items] = $count();
    expect(collect($items)->firstWhere('visibility', 'role')['attendee_count'])->toBe(4);
    $seed(27);
    [$many, $items] = $count();
    expect(count($items))->toBeGreaterThan(100)->and($many)->toBe($few);
});

it('answers 404 for invisible events on update and delete, 403 only for visible ones', function () {
    $owner = cal_user(['calendar.view', 'calendar.create']);
    $private = cal_event(['visibility' => 'private', 'created_by' => $owner->id]);
    $public = cal_event(['visibility' => 'all', 'created_by' => $owner->id]);
    $other = cal_user(['calendar.view', 'calendar.create']);

    $this->actingAs($other)->putJson("/calendar/{$private->id}", cal_payload())->assertNotFound();
    $this->actingAs($other)->deleteJson("/calendar/{$private->id}")->assertNotFound();
    $this->actingAs($other)->putJson("/calendar/{$public->id}", cal_payload())->assertForbidden();
    $this->actingAs($other)->deleteJson("/calendar/{$public->id}")->assertForbidden();
    $locked = cal_locked($owner);
    $locked->forceFill(['visibility' => 'private'])->save();
    $this->actingAs($other)->putJson("/calendar/{$locked->id}", cal_payload())->assertNotFound();
    $this->actingAs($other)->deleteJson("/calendar/{$locked->id}")->assertNotFound();
});

it('stops a creator who lost every calendar right', function () {
    $creator = cal_user([]);
    $e = cal_event(['visibility' => 'all', 'created_by' => $creator->id]);

    expect($creator->can('update', $e))->toBeFalse();
    $this->actingAs($creator)->putJson("/calendar/{$e->id}", cal_payload())->assertNotFound();
    $this->actingAs($creator)->deleteJson("/calendar/{$e->id}")->assertNotFound();
});

it('keeps the organizer row when the creator account is gone', function () {
    $creator = cal_user(['calendar.view', 'calendar.create']);
    $guest = Personnel::factory()->create();
    $id = $this->actingAs($creator)->postJson('/calendar', cal_payload(['visibility' => 'attendees', 'attendee_ids' => [$guest->id]]))->json('id');
    $pid = $creator->personnel_id;
    $creator->delete();

    $this->actingAs(cal_user(['calendar.manage']))->putJson("/calendar/{$id}", cal_payload(['visibility' => 'attendees', 'attendee_ids' => []]))->assertOk();
    expect(CalendarAttendee::where('event_id', $id)->pluck('personnel_id')->all())->toBe([$pid])
        ->and(CalendarEvent::find($id)->created_by)->toBeNull();
});

it('drops finished series in the pre-filter and returns single timed events as stored', function () {
    $old = cal_event(['recurrence_rule' => 'FREQ=DAILY', 'starts_at' => '2020-01-01 08:00:00', 'ends_at' => '2020-01-01 09:00:00', 'recurrence_until' => '2020-02-01 08:00:00']);
    $open = cal_event(['recurrence_rule' => 'FREQ=DAILY', 'starts_at' => '2020-01-01 08:00:00', 'ends_at' => '2020-01-01 09:00:00']);
    $ids = CalendarEvent::query()->touching(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-12-01'))->pluck('id')->all();
    expect($ids)->toContain($open->id)->not->toContain($old->id);

    $e = cal_event(['starts_at' => '2026-10-25 01:30:00', 'ends_at' => '2026-10-25 02:00:00']);
    $item = collect($this->actingAs(cal_user())->getJson('/calendar/events?from=2026-10-24&to=2026-10-26')->json())->firstWhere('id', $e->id);
    expect([$item['occurrence_start'], $item['occurrence_end']])->toBe(['2026-10-25T01:30:00Z', '2026-10-25T02:00:00Z']);
});

it('allows 31-day series occurrences and long single events', function () {
    $user = cal_user(['calendar.view', 'calendar.create']);

    $this->actingAs($user)->postJson('/calendar', cal_payload(['recurrence_rule' => 'FREQ=MONTHLY', 'all_day' => true, 'starts_at' => '2026-11-01', 'ends_at' => '2026-12-02']))->assertUnprocessable();
    $this->actingAs($user)->postJson('/calendar', cal_payload(['recurrence_rule' => 'FREQ=MONTHLY', 'all_day' => true, 'starts_at' => '2026-11-01', 'ends_at' => '2026-12-01']))->assertCreated();
    $this->actingAs($user)->postJson('/calendar', cal_payload(['all_day' => true, 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31']))->assertCreated();
});

it('keeps a long series occurrence that runs into the window, prunes series ended long before', function () {
    $user = cal_user();
    $course = cal_event(['all_day' => true, 'starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-10 00:00:00',
        'recurrence_rule' => 'FREQ=WEEKLY;INTERVAL=1', 'recurrence_until' => '2026-10-29 00:00:00']);
    $ended = cal_event(['recurrence_rule' => 'FREQ=DAILY;INTERVAL=1', 'starts_at' => '2026-09-01 08:00:00', 'ends_at' => '2026-09-01 09:00:00',
        'recurrence_until' => '2026-09-29 08:00:00']);

    $feed = collect($this->actingAs($user)->getJson('/calendar/events?from=2026-11-01&to=2026-12-01')->assertOk()->json());
    expect($feed->where('id', $course->id)->map(fn ($i) => [$i['occurrence_start'], $i['occurrence_end']])->values()->all())->toBe([['2026-10-29', '2026-11-07']]);

    $ids = CalendarEvent::query()->touching(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-12-01'))->pluck('id')->all();
    expect($ids)->toContain($course->id)->not->toContain($ended->id);
});

it('lets calendar.manage without calendar.create look personnel up', function () {
    Personnel::factory()->create(['full_name' => 'Zora Gast']);

    $this->actingAs(cal_user(['calendar.view', 'calendar.manage']))->getJson('/calendar/personnel?q=Zora')->assertOk()->assertJsonCount(1);
    $this->actingAs(cal_user(['calendar.manage']))->getJson('/calendar/personnel')->assertOk();
    $this->actingAs(cal_user(['calendar.view']))->getJson('/calendar/personnel')->assertForbidden();
});
