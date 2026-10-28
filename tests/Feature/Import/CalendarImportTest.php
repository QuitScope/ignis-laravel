<?php

use App\Import\CalendarImporter;
use App\Import\NotificationImporter;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Calendar\Http\Requests\CalendarEventRequest;
use Modules\Calendar\Models\CalendarEvent;
use Modules\Calendar\Support\RecurrenceExpander;
use Tests\Support\LegacyDatabase;
use Tests\Support\LegacySeed;

uses(RefreshDatabase::class)->group('legacy');

const CIM_TOKEN_A = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718';
const CIM_TOKEN_C = 'cccccccccccccccccccccccccccccccccccccccccccccccc';

beforeEach(function () {
    if (! LegacySeed::boot()) {
        $this->markTestSkipped('LEGACY_TEST_DB_HOST not set');
    }
    $this->seed(RoleSeeder::class);
    config(['ignis.import.legacy_timezone' => 'America/New_York', 'ignis.timezone' => 'Europe/Berlin']);
});

afterEach(function () {
    if (LegacyDatabase::available()) {
        LegacySeed::calendarQuirksUndo();
    }
});

function cimSeed(): void
{
    LegacySeed::users([
        ['id' => 1, 'full_admin' => 1, 'ical_token' => CIM_TOKEN_A],
        ['id' => 2, 'ical_token' => 'NOT-A-TOKEN'],
        ['id' => 3, 'ical_token' => CIM_TOKEN_C],
        ['id' => 4, 'discord_id' => '', 'ical_token' => str_repeat('d', 48)],
    ]);
    LegacySeed::personnel([['id' => 1], ['id' => 2]]);
    LegacySeed::formTypes([['id' => 1, 'name' => 'Urlaubsantrag']]);
    LegacySeed::formFields([
        ['id' => 1, 'antragstyp_id' => 1, 'feldname' => 'von_datum', 'feldtyp' => 'date'],
        ['id' => 2, 'antragstyp_id' => 1, 'feldname' => 'bis_datum', 'feldtyp' => 'date'],
        ['id' => 3, 'antragstyp_id' => 1, 'feldname' => 'grund', 'feldtyp' => 'textarea'],
    ]);
    LegacySeed::forms([['id' => 1, 'antragstyp_id' => 1, 'mitarbeiter_id' => 1, 'cirs_status' => 3, 'cirs_time' => '2026-10-01 12:00:00']]);
    LegacySeed::formData([[1, 'von_datum', '2026-11-10T00:00'], [1, 'bis_datum', '2026-11-12'], [1, 'grund', 'Geheimer Grund']]);

    $long = str_repeat('ä', 200);
    LegacySeed::calendarQuirks();
    LegacySeed::calendarEvents([
        ['id' => 1, 'title' => 'Sommer', 'description' => "Zeile 1\nZeile 2", 'location' => ' Wache ', 'starts_at' => '2026-07-15 10:00:00',
            'ends_at' => '2026-07-15 12:00:00', 'color' => 'orange', 'category' => 'dienst', 'visibility' => 'attendees'],
        ['id' => 2, 'starts_at' => '2026-01-15 10:00:00', 'ends_at' => '2026-01-15 11:30:00', 'color' => 'purple', 'category' => 'training', 'visibility' => 'private', 'created_by' => 2],
        ['id' => 3, 'all_day' => 1, 'starts_at' => '2026-07-20 00:00:00', 'ends_at' => '2026-07-22 23:59:59'],
        ['id' => 4, 'all_day' => 1, 'starts_at' => '2026-01-05 00:00:00', 'ends_at' => '2026-01-05 23:59:59', 'category' => 'absence', 'color' => 'gray'],
        ['id' => 5, 'starts_at' => '2026-03-08 02:30:00', 'ends_at' => '2026-03-08 04:00:00'],
        ['id' => 6, 'starts_at' => '2026-11-01 01:30:00', 'ends_at' => '2026-11-01 03:00:00'],
        ['id' => 7, 'title' => $long, 'description' => str_repeat('x', 2500), 'color' => 'pink', 'category' => 'party', 'visibility' => 'bogus', 'created_by' => 55],
        ['id' => 8, 'starts_at' => '2026-05-05 10:00:00', 'ends_at' => '2026-05-05 09:00:00'],
        ['id' => 9, 'all_day' => 1, 'starts_at' => '2026-05-07 00:00:00', 'ends_at' => '2026-05-06 23:59:59'],
        ['id' => 10, 'recurrence_rule' => 'FREQ=WEEKLY'],
        ['id' => 11, 'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO,FR'],
        ['id' => 12, 'recurrence_rule' => 'FREQ=DAILY;COUNT=5;UNTIL=2026-11-06'],
        ['id' => 13, 'recurrence_rule' => 'FREQ=DAILY;COUNT=10', 'recurrence_until' => '2026-11-06 00:00:00'],
        ['id' => 14, 'recurrence_rule' => 'FREQ=DAILY;COUNT=2', 'recurrence_until' => '2026-12-31 00:00:00'],
        ['id' => 15, 'recurrence_rule' => 'FREQ=YEARLY'],
        ['id' => 16, 'recurrence_rule' => 'FREQ=DAILY;UNTIL=2026-11-10'],
        ['id' => 17, 'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=WE;UNTIL=20261125T120000Z'],
        ['id' => 18, 'all_day' => 1, 'starts_at' => '2026-11-04 00:00:00', 'ends_at' => '2026-11-04 23:59:59', 'recurrence_rule' => 'FREQ=MONTHLY;UNTIL=2027-03-31'],
        ['id' => 19, 'recurrence_rule' => 'FREQ=DAILY;UNTIL=2026-02-30'],
        ['id' => 20, 'all_day' => 1, 'starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2026-12-15 23:59:59', 'recurrence_rule' => 'FREQ=MONTHLY'],
        ['id' => 21, 'recurrence_rule' => 'FREQ=DAILY;INTERVAL=5000'],
        ['id' => 22, 'recurrence_rule' => 'FREQ=DAILY;INTERVAL=0'],
        ['id' => 23, 'recurrence_rule' => 'freq=monthly;interval=2;bymonthday=4;x-evil=1'],
        ['id' => 24, 'recurrence_rule' => 'FREQ=DAILY;UNTIL=2026-11-01'],
        ['id' => 25, 'recurrence_rule' => 'FREQ=DAILY', 'parent_event_id' => 10],
        ['id' => 26, 'recurrence_rule' => 'FREQ=DAILY;UNTIL=2026-11-08T18:00:00'],
        ['id' => 27, 'recurrence_rule' => 'FREQ=DAILY;COUNT=20000'],
        ['id' => 28, 'all_day' => 1, 'starts_at' => '2026-11-04 00:00:00', 'ends_at' => '2026-11-04 23:59:59', 'recurrence_rule' => 'FREQ=WEEKLY', 'recurrence_until' => '2026-12-02 00:00:00'],
        ['id' => 29, 'recurrence_rule' => 'FREQ=WEEKLY;INTERVAL=2', 'recurrence_until' => '2026-12-02 18:30:00'],
        ['id' => 32, 'all_day' => 1, 'starts_at' => '2026-11-04 00:00:00', 'ends_at' => '2026-11-05 23:59:59', 'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=SA;COUNT=3'],
        ['id' => 33, 'all_day' => 1, 'starts_at' => '2026-08-01 00:00:00', 'ends_at' => '2026-08-03 00:00:00'],
        ['id' => 30, 'title' => 'Urlaub: Person 1', 'description' => 'Antrag #1 Geheimer Grund', 'all_day' => 1, 'source' => 'antrag', 'source_ref_id' => 1,
            'starts_at' => '2026-11-10 00:00:00', 'ends_at' => '2026-11-12 23:59:59', 'category' => 'absence', 'color' => 'gray'],
        ['id' => 31, 'source' => 'antrag', 'source_ref_id' => 99],
        ['id' => 40, 'visibility' => 'role', 'track_attendance' => 1],
        ['id' => 41, 'visibility' => 'all', 'track_attendance' => 1],
    ]);
    LegacySeed::calendarAttendees([
        ['event_id' => 1, 'mitarbeiter_id' => 1, 'response' => 'accepted', 'responded_at' => '2026-07-01 08:00:00', 'is_organizer' => 1],
        ['event_id' => 1, 'mitarbeiter_id' => 2, 'response' => 'tentative'],
        ['event_id' => 1, 'mitarbeiter_id' => 77, 'response' => 'declined'],
        ['event_id' => 2, 'mitarbeiter_id' => 2, 'response' => 'pending'],
    ]);
    LegacySeed::calendarRoles([[40, 2], [40, 3], [40, 99]]);
    User::factory()->create(['calendar_token_hash' => hash('sha256', CIM_TOKEN_C)]);
}

function cimEvent(int $legacyId): CalendarEvent
{
    $id = DB::table('legacy_id_map')->where(['entity' => 'calendar_event', 'legacy_id' => (string) $legacyId])->value('new_id');

    return CalendarEvent::findOrFail($id);
}

function cimUser(int $legacyId): User
{
    return User::findOrFail(DB::table('legacy_id_map')->where(['entity' => 'user', 'legacy_id' => (string) $legacyId])->value('new_id'));
}

/** @return array<string, mixed> */
function cimDump(): array
{
    return collect(['calendar_events', 'calendar_attendees', 'calendar_event_role', 'legacy_id_map'])
        ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->get()->map(fn ($r) => (array) $r)->sortBy(fn ($r) => json_encode($r))->values()->all()])
        ->put('tokens', DB::table('users')->orderBy('id')->pluck('calendar_token_hash', 'id')->all())->all();
}

/** @param array<string, mixed> $json */
function cimWarning(array $json, string $needle): string
{
    return (string) collect($json['warnings'])->first(fn (string $w) => str_contains($w, $needle));
}

it('imports events with the time rules, mapped values, attendees, roles and creators', function () {
    cimSeed();
    $json = LegacySeed::run(['calendar']);

    expect($json['exit'])->toBe(0)
        ->and($json['entities']['calendar_event'])->toBe(['read' => 35, 'written' => 33, 'skipped' => 2, 'failed' => 0])
        ->and($json['entities']['calendar_attendee'])->toBe(['read' => 4, 'written' => 3, 'skipped' => 1, 'failed' => 0]);

    $e = cimEvent(1);
    expect($e->starts_at->format('Y-m-d H:i:s'))->toBe('2026-07-15 14:00:00')->and($e->ends_at->format('Y-m-d H:i:s'))->toBe('2026-07-15 16:00:00')
        ->and($e->all_day)->toBeFalse()->and($e->title)->toBe('Sommer')->and($e->description)->toBe("Zeile 1\nZeile 2")->and($e->location)->toBe('Wache')
        ->and($e->color)->toBe('accent')->and($e->category)->toBe('duty')->and($e->visibility)->toBe('attendees')
        ->and($e->created_by)->toBe(cimUser(1)->id)->and($e->recurrence_rule)->toBeNull()->and($e->form_submission_id)->toBeNull()
        ->and($e->created_at->format('Y-m-d H:i:s'))->toBe('2026-10-01 12:00:00');
    expect(cimEvent(2)->starts_at->format('Y-m-d H:i:s'))->toBe('2026-01-15 15:00:00')->and(cimEvent(2)->created_by)->toBe(cimUser(2)->id)
        ->and(cimEvent(2)->color)->toBe('purple')->and(cimEvent(2)->category)->toBe('training')->and(cimEvent(2)->visibility)->toBe('private');

    expect([cimEvent(3)->all_day, cimEvent(3)->getRawOriginal('starts_at'), cimEvent(3)->getRawOriginal('ends_at')])->toBe([true, '2026-07-20 00:00:00', '2026-07-22 00:00:00'])
        ->and([cimEvent(4)->getRawOriginal('starts_at'), cimEvent(4)->getRawOriginal('ends_at'), cimEvent(4)->category])->toBe(['2026-01-05 00:00:00', '2026-01-05 00:00:00', 'absence'])
        ->and([cimEvent(33)->getRawOriginal('starts_at'), cimEvent(33)->getRawOriginal('ends_at')])->toBe(['2026-08-01 00:00:00', '2026-08-03 00:00:00']);

    expect(cimEvent(5)->starts_at->format('Y-m-d H:i:s'))->toBe('2026-03-08 07:30:00')->and(cimEvent(5)->ends_at->format('Y-m-d H:i:s'))->toBe('2026-03-08 08:00:00')
        ->and(cimEvent(6)->starts_at->format('Y-m-d H:i:s'))->toBe('2026-11-01 05:30:00')->and(cimEvent(6)->ends_at->format('Y-m-d H:i:s'))->toBe('2026-11-01 08:00:00');

    $e = cimEvent(7);
    expect(mb_strlen($e->title))->toBe(160)->and(mb_strlen((string) $e->description))->toBe(2000)
        ->and($e->color)->toBe('accent')->and($e->category)->toBe('general')->and($e->visibility)->toBe('private')->and($e->created_by)->toBeNull();
    foreach (['title over 160', 'description over 2000', "unknown color 'pink'", "unknown category 'party'", "unknown visibility 'bogus'", 'creating account not imported'] as $w) {
        expect(cimWarning($json, $w))->toContain('(legacy ids: 7)');
    }

    expect(cimEvent(8)->ends_at->format('Y-m-d H:i:s'))->toBe('2026-05-05 15:00:00')
        ->and(cimEvent(9)->getRawOriginal('ends_at'))->toBe('2026-05-07 00:00:00')
        ->and(cimWarning($json, 'end missing or before the start'))->toStartWith('2 ');

    $rows = DB::table('calendar_attendees')->where('event_id', cimEvent(1)->id)->orderBy('personnel_id')->get();
    expect($rows)->toHaveCount(2)
        ->and([$rows[0]->response, (bool) $rows[0]->is_organizer, $rows[0]->responded_at])->toBe(['accepted', true, '2026-07-01 12:00:00'])
        ->and([$rows[1]->response, (bool) $rows[1]->is_organizer, $rows[1]->responded_at])->toBe(['tentative', false, null])
        ->and(cimWarning($json, 'attendee(s) without an imported personnel file'))->toContain('(legacy ids: 1)');

    $roles = DB::table('legacy_id_map')->where('entity', 'role')->whereIn('legacy_id', ['2', '3'])->pluck('new_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    expect(cimEvent(40)->roles()->pluck('roles.id')->sort()->values()->all())->toBe($roles)
        ->and(cimEvent(40)->track_attendance)->toBeTrue()->and(cimEvent(41)->track_attendance)->toBeFalse()
        ->and(cimWarning($json, 'role(s) not imported'))->toContain('(legacy ids: 40)');
});

it('normalises series rules to the supported subset', function () {
    cimSeed();
    $json = LegacySeed::run(['calendar']);
    $r = fn (int $id) => [cimEvent($id)->recurrence_rule, cimEvent($id)->getRawOriginal('recurrence_until')];

    expect($r(10))->toBe(['FREQ=WEEKLY;INTERVAL=1;BYDAY=WE', null])
        ->and(cimWarning($json, 'Wochenserie ohne Wochentag: Wochentag des Beginns gesetzt'))->toStartWith('3 ')
        ->and($r(11))->toBe(['FREQ=WEEKLY;INTERVAL=1;BYDAY=MO,FR', null])
        ->and([cimEvent(11)->getRawOriginal('starts_at'), cimEvent(11)->getRawOriginal('ends_at')])->toBe(['2026-11-06 15:00:00', '2026-11-06 16:00:00'])
        ->and($r(32))->toBe(['FREQ=WEEKLY;INTERVAL=1;BYDAY=SA;COUNT=3', null])
        ->and([cimEvent(32)->getRawOriginal('starts_at'), cimEvent(32)->getRawOriginal('ends_at')])->toBe(['2026-11-07 00:00:00', '2026-11-08 00:00:00'])
        ->and(cimWarning($json, 'Serienbeginn auf ersten Wochentag der Serie verschoben'))->toContain('(legacy ids: 11, 32)')
        ->and(cimWarning($json, 'Intervall wird jetzt berücksichtigt'))->toContain('(legacy ids: 29)')
        ->and(cimWarning($json, 'series without an end'))->toContain('(legacy ids: 10, 11, 21, 22, 23)')
        ->and($r(12))->toBe(['FREQ=DAILY;INTERVAL=1;COUNT=5', null])
        ->and($r(13))->toBe(['FREQ=DAILY;INTERVAL=1', '2026-11-06 22:59:59'])
        ->and($r(14))->toBe(['FREQ=DAILY;INTERVAL=1;COUNT=2', null])
        ->and(cimWarning($json, 'COUNT and recurrence_until'))->toContain('(legacy ids: 13, 14)')
        ->and($r(15))->toBe([null, null])
        ->and($r(16))->toBe(['FREQ=DAILY;INTERVAL=1', '2026-11-10 22:59:59'])
        ->and($r(17))->toBe(['FREQ=WEEKLY;INTERVAL=1;BYDAY=WE', '2026-11-25 12:00:00'])
        ->and($r(18))->toBe(['FREQ=MONTHLY;INTERVAL=1', '2027-03-31 00:00:00'])
        ->and($r(26))->toBe(['FREQ=DAILY;INTERVAL=1', '2026-11-08 17:00:00'])
        ->and($r(28))->toBe(['FREQ=WEEKLY;INTERVAL=1;BYDAY=WE', '2026-12-02 00:00:00'])
        ->and($r(29))->toBe(['FREQ=WEEKLY;INTERVAL=2;BYDAY=WE', '2026-12-02 23:30:00'])
        ->and($r(19))->toBe([null, null])->and($r(20))->toBe([null, null])->and($r(24))->toBe([null, null])->and($r(25))->toBe([null, null])
        ->and(cimWarning($json, 'UNTIL is no valid date'))->toContain('(legacy ids: 19)')
        ->and(cimWarning($json, 'longer than 31 days'))->toContain('(legacy ids: 20)')
        ->and(cimWarning($json, 'UNTIL before the start'))->toContain('(legacy ids: 24)')
        ->and(cimWarning($json, 'parent_event_id'))->toContain('(legacy ids: 25)')
        ->and(cimWarning($json, 'rule not understood'))->toContain('(legacy ids: 15)')
        ->and($r(21))->toBe(['FREQ=DAILY;INTERVAL=999', null])->and(cimWarning($json, 'INTERVAL over 999'))->toContain('(legacy ids: 21)')
        ->and($r(22))->toBe(['FREQ=DAILY;INTERVAL=1', null])
        ->and($r(27))->toBe(['FREQ=DAILY;INTERVAL=1;COUNT=9999', null])
        ->and($r(23))->toBe(['FREQ=MONTHLY;INTERVAL=2', null])->and(cimWarning($json, 'unsupported parts dropped'))->toContain('(legacy ids: 23)');
});

it('leaves only series the feed and EventRequest accept', function () {
    cimSeed();
    LegacySeed::run(['calendar']);
    $zone = (string) config('ignis.timezone');

    foreach (CalendarEvent::query()->whereNotNull('recurrence_rule')->get() as $e) {
        $rule = RecurrenceExpander::parse($e->recurrence_rule);
        expect($rule)->not->toBeNull()
            ->and(preg_match(CalendarEventRequest::RRULE, $e->recurrence_rule))->toBe(1)
            ->and($rule['count'] !== null && $e->recurrence_until !== null)->toBeFalse();
        $span = $e->starts_at->diffInSeconds($e->ends_at->addDays($e->all_day ? 1 : 0));
        expect($span)->toBeLessThanOrEqual(CalendarEvent::MAX_SERIES_DAYS * 86400);
        if ($rule['freq'] === 'WEEKLY') {
            expect($rule['byday'])->toContain(($e->all_day ? $e->starts_at : $e->starts_at->setTimezone($zone))->dayOfWeekIso);
        }
        if ($e->recurrence_until !== null) {
            expect($e->recurrence_until->greaterThanOrEqualTo($e->starts_at))->toBeTrue();
        }
    }

    $admin = User::factory()->create(['is_super_admin' => true]);
    $items = collect($this->actingAs($admin)->getJson('/calendar/events?from=2026-11-01&to=2026-12-01')->assertOk()->json())
        ->where('id', cimEvent(10)->id);
    expect($items->pluck('occurrence_start')->all())->toBe(['2026-11-04T15:00:00Z', '2026-11-11T15:00:00Z', '2026-11-18T15:00:00Z', '2026-11-25T15:00:00Z']);
});

it('does not import legacy absence mirrors but recreates them from the imported applications in the same run (R8)', function () {
    cimSeed();
    app(Settings::class)->set('modules.forms.enabled', false);
    app(Settings::class)->set('modules.calendar.enabled', false);
    $json = LegacySeed::run(['calendar']);

    expect(CalendarEvent::query()->where('title', 'like', 'Urlaub%')->count())->toBe(0)
        ->and(cimWarning($json, 'recreated from the applications'))->toContain('(legacy ids: 30)')
        ->and(cimWarning($json, 'their application was not imported'))->toContain('(legacy ids: 31)')
        ->and(collect($json['notes'])->implode("\n"))->toContain('Abwesenheitstermine aus Anträgen erzeugt/aktualisiert/entfernt: 1/0/0');
    $mirror = CalendarEvent::query()->whereNotNull('form_submission_id')->sole();
    expect($mirror->title)->toBe('Abwesend: Person 1')->and($mirror->description)->toBeNull()
        ->and($mirror->getRawOriginal('starts_at'))->toBe('2026-11-10 00:00:00')->and($mirror->getRawOriginal('ends_at'))->toBe('2026-11-12 00:00:00')
        ->and($mirror->isLocked())->toBeTrue();

    $again = LegacySeed::run(['calendar']);
    expect(collect($again['notes'])->implode("\n"))->toContain('erzeugt/aktualisiert/entfernt: 0/1/0')
        ->and(CalendarEvent::query()->whereNotNull('form_submission_id')->sole()->id)->toBe($mirror->id);
    LegacyDatabase::connection()->table('intra_antraege')->where('id', 1)->update(['cirs_status' => 1]);
    $flipped = LegacySeed::run(['calendar']);
    expect(collect($flipped['notes'])->implode("\n"))->toContain('erzeugt/aktualisiert/entfernt: 0/0/1')
        ->and(CalendarEvent::query()->whereNotNull('form_submission_id')->count())->toBe(0);
});

it('creates no absence mirror on a dry run', function () {
    cimSeed();
    LegacySeed::run(['calendar'], dry: true);
    expect(CalendarEvent::query()->count())->toBe(0);
});

it('never overwrites the subscription a mapped account already has', function () {
    cimSeed();
    LegacySeed::run(['user']);
    cimUser(1)->forceFill(['calendar_token_hash' => hash('sha256', str_repeat('e', 64))])->save();

    $json = LegacySeed::run(['calendar']);
    expect(cimUser(1)->calendar_token_hash)->toBe(hash('sha256', str_repeat('e', 64)))
        ->and(cimWarning($json, 'the account already has a subscription'))->toContain('(legacy ids: 1)');
});

it('takes over iCal tokens as hashes only; an old subscription keeps working through the real feed', function () {
    cimSeed();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logged) {
        $logged[] = $m->message.json_encode($m->context);
    });
    LegacySeed::calendarEvents([['id' => 50, 'title' => 'Abo-Termin', 'starts_at' => '2026-11-20 10:00:00', 'ends_at' => '2026-11-20 11:00:00']]);
    Artisan::call('ignis:import-legacy', ['--only' => ['calendar']]);
    $text = Artisan::output();
    $json = LegacySeed::run(['calendar']);

    $user = cimUser(1);
    expect($user->calendar_token_hash)->toBe(hash('sha256', CIM_TOKEN_A))
        ->and(cimUser(2)->calendar_token_hash)->toBeNull()
        ->and(cimUser(3)->calendar_token_hash)->toBeNull()
        ->and(User::query()->where('calendar_token_hash', hash('sha256', CIM_TOKEN_C))->count())->toBe(1)
        ->and($json['entities']['calendar_token']['failed'])->toBe(0)
        ->and(cimWarning($json, ''))->not->toBeNull();

    // Own schema only: on MariaDB an unscoped listing also returns the legacy_test tables.
    $schema = DB::connection()->getSchemaBuilder();
    $dump = collect($schema->getTableListing($schema->getCurrentSchemaListing(), false))->map(fn ($t) => json_encode(DB::table($t)->get()))->implode('');
    foreach ([CIM_TOKEN_A, CIM_TOKEN_C, 'NOT-A-TOKEN', str_repeat('d', 48)] as $token) {
        expect($text)->not->toContain($token)->and(json_encode($json))->not->toContain($token)
            ->and(implode('', $logged))->not->toContain($token)->and($dump)->not->toContain($token);
    }

    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC'));
    $body = $this->get('/api/calendar/ical/'.CIM_TOKEN_A)->assertOk()->getContent();
    expect($body)->toContain('SUMMARY:Abo-Termin');
    $this->get('/api/v1/kalender/ical/'.CIM_TOKEN_A)->assertOk();
});

it('reports invalid, duplicate and orphaned tokens by legacy user id only', function () {
    cimSeed();
    $db = LegacyDatabase::connection();
    $db->statement('ALTER TABLE intra_users DROP INDEX uniq_users_ical_token');
    try {
        LegacySeed::users([['id' => 6, 'ical_token' => CIM_TOKEN_A]]);
        $json = LegacySeed::run(['calendar']);
    } finally {
        $db->table('intra_users')->where('id', 6)->delete();
        $db->statement('ALTER TABLE intra_users ADD UNIQUE INDEX uniq_users_ical_token (ical_token)');
    }

    expect(cimUser(1)->calendar_token_hash)->toBe(hash('sha256', CIM_TOKEN_A))->and(cimUser(6)->calendar_token_hash)->toBeNull()
        ->and($json['entities']['calendar_token'])->toBe(['read' => 5, 'written' => 1, 'skipped' => 4, 'failed' => 0])
        ->and(cimWarning($json, 'not 20-64 lower-case hex'))->toContain('(legacy ids: 2)')
        ->and(cimWarning($json, 'already held by another account'))->toContain('(legacy ids: 3, 6)')
        ->and(cimWarning($json, 'accounts that were not imported'))->toContain('(legacy ids: 4)');
});

it('never revives a token the account replaced after the first import', function () {
    cimSeed();
    LegacySeed::run(['calendar']);
    cimUser(1)->forceFill(['calendar_token_hash' => null])->save();

    LegacySeed::run(['calendar']);
    expect(cimUser(1)->calendar_token_hash)->toBeNull();
});

it('gives the same result on a second run', function () {
    cimSeed();
    $first = LegacySeed::run(['calendar']);
    $dump = cimDump();
    $second = LegacySeed::run(['calendar']);

    expect(cimDump())->toBe($dump)
        ->and($second['entities']['calendar_event'])->toBe($first['entities']['calendar_event'])
        ->and(array_values(preg_grep('/calendar/', $second['warnings'])))->toBe(array_values(preg_grep('/calendar/', $first['warnings'])));
});

it('leaves no traces on a dry run', function () {
    cimSeed();
    $before = cimDump();
    $json = LegacySeed::run(['calendar'], dry: true);

    expect($json['exit'])->toBe(0)->and($json['dry_run'])->toBeTrue()->and($json['entities']['calendar_event']['written'])->toBe(33)
        ->and(collect($json['notes'])->implode("\n"))->toContain('Abwesenheitstermine aus Anträgen erzeugt/aktualisiert/entfernt: 1/0/0')
        ->and(cimDump())->toBe($before);
});

it('brings back an event deleted in the new instance', function () {
    cimSeed();
    LegacySeed::run(['calendar']);
    $old = cimEvent(1)->id;
    cimEvent(1)->delete();

    LegacySeed::run(['calendar']);
    expect(cimEvent(1)->id)->not->toBe($old)->and(cimEvent(1)->title)->toBe('Sommer')->and(cimEvent(1)->attendees()->count())->toBe(2);
});

it('isolates a failing row', function () {
    cimSeed();
    LegacySeed::calendarAttendees([['event_id' => 2, 'mitarbeiter_id' => 99]]);
    DB::table('legacy_id_map')->insert(['entity' => 'personnel', 'legacy_id' => '99', 'new_id' => 999999]);

    $json = LegacySeed::run(['calendar']);

    expect($json['exit'])->toBe(1)
        ->and($json['entities']['calendar_event'])->toBe(['read' => 35, 'written' => 32, 'skipped' => 2, 'failed' => 1])
        ->and($json['entities']['calendar_attendee'])->toBe(['read' => 5, 'written' => 2, 'skipped' => 1, 'failed' => 2])
        ->and(implode("\n", $json['failures']))->toContain('legacy calendar event 2')->not->toContain('insert')
        ->and(DB::table('legacy_id_map')->where(['entity' => 'calendar_event', 'legacy_id' => '2'])->exists())->toBeFalse()
        ->and(DB::table('calendar_events')->whereNull('form_submission_id')->count())->toBe(32)
        ->and(cimEvent(1)->attendees()->count())->toBe(2);
});

it('rewrites notification links to calendar events', function () {
    cimSeed();
    LegacySeed::notifications([
        ['id' => 1, 'type' => 'calendar', 'link' => '/calendar/view?id=1'],
        ['id' => 2, 'type' => 'calendar', 'link' => '/kalender/view.php?id=30'],
        ['id' => 3, 'type' => 'calendar', 'link' => '/calendar/create'],
    ]);
    LegacySeed::run(['calendar', 'notification']);

    $link = fn (int $id) => json_decode((string) DB::table('notifications')->where('id', NotificationImporter::uuid($id))->value('data'), true)['link'];
    expect($link(1))->toBe('/calendar?event='.cimEvent(1)->id)->and($link(2))->toBe('/calendar')->and($link(3))->toBe('/calendar?new=1');
});

it('runs as entity calendar after user, personnel, role and form', function () {
    expect((new CalendarImporter)->dependsOn())->toBe(['user', 'personnel', 'role', 'form'])
        ->and((new CalendarImporter)->entity())->toBe('calendar');
});

it('counts a failing absence step as read and failed on its own entity, and still imports the rest', function () {
    cimSeed();
    DB::listen(fn ($query) => str_contains($query->sql, 'form_submissions') ? throw new RuntimeException('boom') : null);

    $json = LegacySeed::run(['calendar']);

    expect($json['exit'])->toBe(1)
        ->and($json['entities']['calendar_absence'])->toBe(['read' => 1, 'written' => 0, 'skipped' => 0, 'failed' => 1])
        ->and($json['entities']['calendar_event']['failed'] ?? 0)->toBe(0)
        ->and(implode("\n", $json['failures']))->toContain('absence mirrors')
        ->and(DB::table('calendar_events')->count())->toBeGreaterThan(0);
});

it('rewrites knowledge base links to calendar events in the same run (calendar runs before kb)', function () {
    cimSeed();
    LegacySeed::kbEntries([['id' => 1, 'content' => '<p><a href="/calendar/view?id=1">Termin</a> <a href="/kalender/view.php?id=30">Spiegel</a></p>']]);

    LegacySeed::run(['kb', 'calendar']);

    $content = json_encode(json_decode((string) DB::table('kb_entries')->value('content')), JSON_UNESCAPED_SLASHES);
    expect($content)->toContain('/calendar?event='.cimEvent(1)->id)->not->toContain('view?id=');
});

it('still creates the absence mirrors when the legacy calendar tables are missing', function () {
    cimSeed();
    LegacyDatabase::connection()->statement('RENAME TABLE intra_calendar_event_roles TO intra_calendar_event_roles_x');
    try {
        $json = LegacySeed::run(['calendar']);
    } finally {
        LegacyDatabase::connection()->statement('RENAME TABLE intra_calendar_event_roles_x TO intra_calendar_event_roles');
    }

    expect($json['exit'])->toBe(0)
        ->and(implode("\n", $json['notes']))->toContain('legacy table intra_calendar_event_roles missing')->toContain('erzeugt/aktualisiert/entfernt: 1/0/0')
        ->and(DB::table('calendar_events')->count())->toBe(1)
        ->and(CalendarEvent::query()->whereNotNull('form_submission_id')->count())->toBe(1);
});
