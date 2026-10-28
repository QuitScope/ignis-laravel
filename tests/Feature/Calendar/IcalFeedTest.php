<?php

use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Modules\Calendar\Models\CalendarEvent;
use Modules\Calendar\Support\IcalExporter;
use Modules\Calendar\Support\IcalToken;
use Modules\Forms\Models\FormType;

require_once __DIR__.'/calendarHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC'));
});

/**
 * Minimal RFC 5545 reader: checks CRLF, line length (75 octets), UTF-8 and BEGIN/END balance, unfolds, and returns the
 * components as [type, [[name, params, value], ...]] (nested components are listed in order, not nested).
 *
 * @return list<array{0: string, 1: list<array{0: string, 1: string, 2: string}>}>
 */
function ics_parse(string $ics): array
{
    expect(str_ends_with($ics, "\r\n"))->toBeTrue('ends with CRLF');
    $physical = explode("\r\n", substr($ics, 0, -2));
    $logical = [];
    foreach ($physical as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75, 'line over 75 octets: '.$line)
            ->and(mb_check_encoding($line, 'UTF-8'))->toBeTrue('invalid UTF-8: '.bin2hex($line))
            ->and(str_contains($line, "\r") || str_contains($line, "\n"))->toBeFalse('bare CR/LF');
        if ($line !== '' && $line[0] === ' ') {
            expect($logical)->not->toBe([]);
            $logical[count($logical) - 1] .= substr($line, 1);
        } else {
            $logical[] = $line;
        }
    }

    $stack = [];
    $components = [];
    foreach ($logical as $line) {
        expect($line)->toMatch('/^[A-Z][A-Z0-9-]*(;[^:]*)?:/');
        $colon = strpos($line, ':');
        $head = substr($line, 0, $colon);
        $value = substr($line, $colon + 1);
        [$name, $params] = array_pad(explode(';', $head, 2), 2, '');
        if ($name === 'BEGIN') {
            $stack[] = $value;
            $components[] = [$value, []];
        } elseif ($name === 'END') {
            expect(array_pop($stack))->toBe($value);
        } else {
            expect($stack)->not->toBe([]);
            $components[count($components) - 1][1][] = [$name, $params, $value];
        }
    }
    expect($stack)->toBe([])->and($components[0][0] ?? null)->toBe('VCALENDAR');

    return $components;
}

/** @return list<array<string, array{0: string, 1: string}>> VEVENTs as name => [params, value] (first wins) */
function ics_events(string $ics): array
{
    $events = [];
    foreach (ics_parse($ics) as [$type, $props]) {
        if ($type !== 'VEVENT') {
            continue;
        }
        $map = [];
        foreach ($props as [$name, $params, $value]) {
            $map[$name] ??= [$params, $value];
        }
        expect($map)->toHaveKeys(['UID', 'DTSTAMP', 'DTSTART']);
        $events[] = $map;
    }

    return $events;
}

/** @return array<int, array<string, array{0: string, 1: string}>> keyed by event id */
function ics_by_id(string $ics): array
{
    $out = [];
    foreach (ics_events($ics) as $e) {
        preg_match('/^cal-(\d+)@/', $e['UID'][1], $m);
        expect($out)->not->toHaveKey((int) $m[1]);
        $out[(int) $m[1]] = $e;
    }
    ksort($out);

    return $out;
}

function ics_unescape(string $v): string
{
    return (string) preg_replace_callback('/\\\\([\\\\;,nN])/', fn ($m) => strtolower($m[1]) === 'n' ? "\n" : $m[1], $v);
}

function ics_feed(User $user): string
{
    $res = test()->get('/api/calendar/ical/'.IcalToken::issue($user))->assertOk();

    return $res->getContent();
}

function ical_locked(User $creator, array $over = []): CalendarEvent
{
    $type = (new FormType)->forceFill(['name' => 'Urlaub', 'is_active' => true, 'is_absence' => true, 'sort_order' => 1]);
    $type->save();
    $sid = DB::table('form_submissions')->insertGetId(['public_id' => (string) random_int(100000, 999999), 'form_type_id' => $type->id,
        'applicant_name' => 'X', 'status' => 'accepted', 'submitted_at' => now(), 'answers' => '[]']);

    return cal_event($over + ['form_submission_id' => $sid, 'created_by' => $creator->id, 'all_day' => true, 'category' => 'absence',
        'title' => 'Abwesend: Max', 'starts_at' => '2026-11-10 00:00:00', 'ends_at' => '2026-11-12 00:00:00']);
}

function ics_fingerprint(TestResponse $res): array
{
    $headers = $res->headers->all();
    unset($headers['date'], $headers['x-ratelimit-limit'], $headers['x-ratelimit-remaining']);
    ksort($headers);

    return [$res->getStatusCode(), $res->getContent(), $headers];
}

it('serves a valid calendar with the required headers and no cookie', function () {
    config(['app.url' => 'https://intra.example.org', 'app.name' => 'Ignis; Wache, Nord']);
    $user = cal_user();
    cal_event(['visibility' => 'all']);

    $res = $this->get('/api/calendar/ical/'.IcalToken::issue($user))->assertOk();

    expect($res->headers->get('Content-Type'))->toBe('text/calendar; charset=utf-8')
        ->and($res->headers->get('Content-Disposition'))->toBe('inline; filename="calendar.ics"')
        ->and(explode(', ', (string) $res->headers->get('Cache-Control')))->toEqualCanonicalizing(['max-age=300', 'private'])
        ->and($res->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($res->headers->has('Set-Cookie'))->toBeFalse();

    $calendar = ics_parse($res->getContent())[0][1];
    $props = [];
    foreach ($calendar as [$name, , $value]) {
        $props[$name] = $value;
    }
    expect($props)->toMatchArray(['VERSION' => '2.0', 'CALSCALE' => 'GREGORIAN', 'METHOD' => 'PUBLISH', 'X-WR-CALNAME' => 'Ignis\\; Wache\\, Nord'])
        ->and($props)->toHaveKey('PRODID');
});

it('answers every failure with the same 404, without cookie and without detail', function () {
    config(['app.debug' => false]);
    $token = str_repeat('ab', 32);
    $user = cal_user();
    $responses = [];

    $responses['unknown'] = $this->get('/api/calendar/ical/'.$token);

    $user->forceFill(['calendar_token_hash' => hash('sha256', $token), 'is_active' => false])->save();
    $responses['inactive'] = $this->get('/api/calendar/ical/'.$token);

    $noRight = cal_user(['calendar.create']);
    $user->forceFill(['calendar_token_hash' => null])->save();
    $noRight->forceFill(['calendar_token_hash' => hash('sha256', $token)])->save();
    $responses['no right'] = $this->get('/api/calendar/ical/'.$token);

    $noRight->forceFill(['calendar_token_hash' => null])->save();
    $user->forceFill(['calendar_token_hash' => hash('sha256', $token), 'is_active' => true])->save();
    $this->get('/api/calendar/ical/'.$token)->assertOk();
    app(Settings::class)->set('modules.calendar.enabled', false);
    $responses['module off'] = $this->get('/api/calendar/ical/'.$token);

    $first = ics_fingerprint($responses['unknown']);
    expect($first[0])->toBe(404)->and($first[2])->not->toHaveKey('set-cookie');
    foreach ($responses as $case => $res) {
        expect(ics_fingerprint($res))->toBe($first, $case);
    }

    app(Settings::class)->set('modules.calendar.enabled', true);
    foreach (['zz'.substr($token, 2), substr($token, 0, 19), $token.'a', strtoupper($token)] as $bad) {
        [$status, $body, $headers] = ics_fingerprint($this->get('/api/calendar/ical/'.$bad));
        expect([$status, str_replace($bad, $token, (string) $body), $headers])->toBe($first, $bad);
    }
    expect((string) $first[1])->not->toContain('calendar_token_hash')->not->toContain('inactive');
});

it('throttles the feed per address after 30 requests a minute', function () {
    $token = IcalToken::issue(cal_user());
    for ($i = 0; $i < 30; $i++) {
        $this->get('/api/calendar/ical/'.($i % 2 === 0 ? $token : str_repeat('c', 64)))->assertStatus($i % 2 === 0 ? 200 : 404);
    }
    $this->get('/api/calendar/ical/'.$token)->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])->get('/api/calendar/ical/'.$token)->assertOk();
});

it('reaches the feed under the legacy paths, also with a 48 hex token', function () {
    $user = cal_user();
    $legacy = str_repeat('0123456789abcdef', 3);
    $user->forceFill(['calendar_token_hash' => hash('sha256', $legacy)])->save();
    cal_event(['visibility' => 'all', 'title' => 'Alias-Test']);

    $body = $this->get('/api/calendar/ical/'.$legacy)->assertOk()->getContent();
    foreach (['/api/v1/calendar/ical/', '/api/kalender/ical/', '/api/v1/kalender/ical/'] as $prefix) {
        $res = $this->get($prefix.$legacy)->assertOk();
        expect($res->getContent())->toBe($body)->and($res->headers->get('Content-Type'))->toBe('text/calendar; charset=utf-8');
    }
    expect($body)->toContain('SUMMARY:Alias-Test');
});

it('exports exactly what the page shows each account', function () {
    $creator = cal_user(['calendar.view', 'calendar.create']);
    $role = cal_role();
    $member = cal_user(['calendar.view'], true, [$role]);
    $invited = cal_user();
    $other = cal_user();

    cal_event(['visibility' => 'private', 'created_by' => $creator->id], [], [$invited->personnel_id => 'pending']);
    cal_event(['visibility' => 'attendees', 'created_by' => $creator->id], [], [$invited->personnel_id => 'pending']);
    cal_event(['visibility' => 'role', 'created_by' => $creator->id], [$role]);
    cal_event(['visibility' => 'all', 'created_by' => $creator->id]);

    foreach ([$creator, $member, $invited, $other] as $user) {
        expect(array_keys(ics_by_id(ics_feed($user))))->toBe(cal_feed_ids($user));
    }
});

it('limits the export to six months back and twelve ahead', function () {
    $user = cal_user();
    $old = cal_event(['starts_at' => '2026-04-01 10:00:00', 'ends_at' => '2026-04-01 11:00:00']);
    $edge = cal_event(['starts_at' => '2026-04-21 11:00:00', 'ends_at' => '2026-04-21 13:00:00']);
    $in = cal_event();
    $far = cal_event(['starts_at' => '2027-10-22 10:00:00', 'ends_at' => '2027-10-22 11:00:00']);
    $series = cal_event(['starts_at' => '2024-01-02 17:00:00', 'ends_at' => '2024-01-02 19:00:00', 'recurrence_rule' => 'FREQ=WEEKLY;INTERVAL=1']);
    $ended = cal_event(['starts_at' => '2024-01-02 17:00:00', 'ends_at' => '2024-01-02 19:00:00', 'recurrence_rule' => 'FREQ=DAILY;INTERVAL=1;COUNT=3']);

    expect(array_keys(ics_by_id(ics_feed($user))))->toBe([$edge->id, $in->id, $series->id]);
});

it('writes timed events in UTC and all-day events as dates with an exclusive end', function () {
    config(['app.url' => 'https://intra.example.org:8443/base']);
    $user = cal_user();
    $timed = cal_event(['starts_at' => '2026-11-10 08:00:00', 'ends_at' => '2026-11-10 10:30:00']);
    $allDay = cal_event(['all_day' => true, 'starts_at' => '2026-12-31 00:00:00', 'ends_at' => '2027-01-01 00:00:00']);
    $oneDay = cal_event(['all_day' => true, 'starts_at' => '2026-11-15 00:00:00', 'ends_at' => '2026-11-15 00:00:00']);
    $zero = cal_event(['starts_at' => '2026-11-11 08:00:00', 'ends_at' => '2026-11-11 08:00:00']);
    DB::table('calendar_events')->where('id', $timed->id)->update(['updated_at' => '2026-10-01 07:08:09']);

    $events = ics_by_id(ics_feed($user));

    expect($events[$timed->id]['UID'][1])->toBe("cal-{$timed->id}@intra.example.org")
        ->and($events[$timed->id]['DTSTART'])->toBe(['', '20261110T080000Z'])
        ->and($events[$timed->id]['DTEND'])->toBe(['', '20261110T103000Z'])
        ->and($events[$timed->id]['DTSTAMP'])->toBe(['', '20261001T070809Z'])
        ->and($events[$timed->id]['STATUS'][1])->toBe('CONFIRMED')
        ->and($events[$timed->id])->toHaveKey('CATEGORIES')
        ->and($events[$allDay->id]['DTSTART'])->toBe(['VALUE=DATE', '20261231'])
        ->and($events[$allDay->id]['DTEND'])->toBe(['VALUE=DATE', '20270102'])
        ->and($events[$oneDay->id]['DTEND'])->toBe(['VALUE=DATE', '20261116'])
        ->and($events[$zero->id])->not->toHaveKey('DTEND');
    foreach ($events as $e) {
        expect($e['DTSTAMP'][1])->toMatch('/^\d{8}T\d{6}Z$/')->and($e)->not->toHaveKeys(['ATTENDEE', 'ORGANIZER']);
    }
});

it('exports a series once with its normalised rule and a valid UNTIL', function () {
    $user = cal_user();
    $weekly = cal_event(['starts_at' => '2026-01-06 17:00:00', 'ends_at' => '2026-01-06 19:00:00', 'recurrence_rule' => 'FREQ=WEEKLY;INTERVAL=2;BYDAY=TU,TH',
        'recurrence_until' => '2026-12-31 22:59:59']);
    $daily = cal_event(['starts_at' => '2026-11-01 07:00:00', 'ends_at' => '2026-11-01 08:00:00', 'recurrence_rule' => 'FREQ=DAILY;INTERVAL=3;COUNT=5']);
    $allDay = cal_event(['all_day' => true, 'starts_at' => '2026-11-02 00:00:00', 'ends_at' => '2026-11-03 00:00:00',
        'recurrence_rule' => 'FREQ=MONTHLY;INTERVAL=1', 'recurrence_until' => '2027-03-02 00:00:00']);
    $legacy = cal_event(['starts_at' => '2026-11-03 17:00:00', 'ends_at' => '2026-11-03 18:00:00', 'recurrence_rule' => 'freq=weekly;until=2026-12-31;X-EVIL=1']);
    $broken = cal_event(['starts_at' => '2026-11-04 17:00:00', 'ends_at' => '2026-11-04 18:00:00', 'recurrence_rule' => 'FREQ=YEARLY']);

    $events = ics_by_id(ics_feed($user));

    expect($events[$weekly->id]['RRULE'][1])->toBe('FREQ=WEEKLY;INTERVAL=2;BYDAY=TU,TH;UNTIL=20261231T225959Z')
        ->and($events[$daily->id]['RRULE'][1])->toBe('FREQ=DAILY;INTERVAL=3;COUNT=5')
        ->and($events[$allDay->id]['RRULE'][1])->toBe('FREQ=MONTHLY;INTERVAL=1;UNTIL=20270302')
        ->and($events[$allDay->id]['DTSTART'])->toBe(['VALUE=DATE', '20261102'])
        ->and($events[$legacy->id]['RRULE'][1])->toBe('FREQ=WEEKLY;INTERVAL=1;UNTIL=20261231T225959Z')
        ->and($events[$broken->id])->not->toHaveKey('RRULE');

    expect($events[$weekly->id]['DTSTART'])->toBe(['TZID=Europe/Berlin', '20260106T180000'])
        ->and($events[$weekly->id]['DTEND'])->toBe(['TZID=Europe/Berlin', '20260106T200000'])
        ->and($events[$daily->id]['DTSTART'])->toBe(['TZID=Europe/Berlin', '20261101T080000']);
});

it('defines the organisation zone once when a timed series needs it', function () {
    $user = cal_user();
    cal_event();
    $plain = ics_feed($user);
    expect($plain)->not->toContain('BEGIN:VTIMEZONE');

    cal_event(['starts_at' => '2026-11-01 07:00:00', 'ends_at' => '2026-11-01 08:00:00', 'recurrence_rule' => 'FREQ=DAILY;INTERVAL=1']);
    $components = ics_parse(ics_feed($user));
    $types = array_column($components, 0);
    expect(array_count_values($types)['VTIMEZONE'] ?? 0)->toBe(1)
        ->and($types)->toContain('STANDARD')->toContain('DAYLIGHT');

    $zone = collect($components)->first(fn ($c) => $c[0] === 'VTIMEZONE')[1];
    expect($zone[0])->toBe(['TZID', '', 'Europe/Berlin']);
    $obs = collect($components)->filter(fn ($c) => in_array($c[0], ['STANDARD', 'DAYLIGHT'], true))
        ->mapWithKeys(fn ($c) => [$c[0] => collect($c[1])->mapWithKeys(fn ($p) => [$p[0] => $p[2]])->all()])->all();
    expect($obs['DAYLIGHT'])->toMatchArray(['TZOFFSETFROM' => '+0100', 'TZOFFSETTO' => '+0200', 'RRULE' => 'FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', 'DTSTART' => '19700329T020000'])
        ->and($obs['STANDARD'])->toMatchArray(['TZOFFSETFROM' => '+0200', 'TZOFFSETTO' => '+0100', 'RRULE' => 'FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', 'DTSTART' => '19701025T030000']);
});

it('uses a single observance for a zone without daylight saving time', function () {
    config(['ignis.timezone' => 'Asia/Tokyo']);
    $user = cal_user();
    cal_event(['starts_at' => '2026-11-01 07:00:00', 'ends_at' => '2026-11-01 08:00:00', 'recurrence_rule' => 'FREQ=DAILY;INTERVAL=1']);

    $components = ics_parse(ics_feed($user));
    $obs = collect($components)->filter(fn ($c) => in_array($c[0], ['STANDARD', 'DAYLIGHT'], true))->values();
    expect($obs)->toHaveCount(1)->and($obs[0][0])->toBe('STANDARD')
        ->and(collect($obs[0][1])->mapWithKeys(fn ($p) => [$p[0] => $p[2]])->all())->toMatchArray(['TZOFFSETFROM' => '+0900', 'TZOFFSETTO' => '+0900']);
});

it('escapes and folds text safely (umlauts, emoji, CR/LF, separators, very long lines)', function () {
    $user = cal_user();
    $title = 'Übung; Wache, Nord \\ "Zug" 🚒🚑 '.str_repeat('Äöü€😀', 40);
    $location = "Gerätehaus\r\nHof; Tor 2, links";
    $description = "Zeile 1\r\nZeile 2\rX\nZeile 3; a, b \\n bleibt\n".str_repeat('ß', 120);
    $e = cal_event(['title' => $title, 'location' => $location, 'description' => $description]);

    $ics = ics_feed($user);
    $event = ics_by_id($ics)[$e->id];

    expect(ics_unescape($event['SUMMARY'][1]))->toBe($title)
        ->and(ics_unescape($event['LOCATION'][1]))->toBe("Gerätehaus\nHof; Tor 2, links")
        ->and(ics_unescape($event['DESCRIPTION'][1]))->toBe("Zeile 1\nZeile 2X\nZeile 3; a, b \\n bleibt\n".str_repeat('ß', 120))
        ->and($event['SUMMARY'][1])->toContain('Übung\\; Wache\\, Nord \\\\ "Zug"');
});

it('folds at 75 octets without splitting a multibyte character', function () {
    foreach ([str_repeat('a', 200), str_repeat('ä', 100), 'SUMMARY:'.str_repeat('😀', 50), str_repeat('x', 74).'€'.str_repeat('y', 80)] as $line) {
        $folded = IcalExporter::fold($line);
        foreach (explode("\r\n", $folded) as $i => $physical) {
            expect(strlen($physical))->toBeLessThanOrEqual(75)->and(mb_check_encoding($physical, 'UTF-8'))->toBeTrue();
            if ($i > 0) {
                expect($physical[0])->toBe(' ');
            }
        }
        expect(str_replace("\r\n ", '', $folded))->toBe($line);
    }
    expect(IcalExporter::fold(str_repeat('a', 75)))->toBe(str_repeat('a', 75));
});

it('exports absences without their description', function () {
    $user = cal_user();
    $e = ical_locked(cal_user(), ['description' => 'Grund: Krankheit', 'location' => null]);

    $ics = ics_feed($user);
    $event = ics_by_id($ics)[$e->id];

    expect($event)->not->toHaveKey('DESCRIPTION')
        ->and($event['SUMMARY'][1])->toBe('Abwesend: Max')
        ->and($ics)->not->toContain('Krankheit');
});

it('caps the export and logs the truncation without the token', function () {
    $user = cal_user();
    foreach (range(1, 5) as $i) {
        cal_event(['starts_at' => "2026-11-1{$i} 08:00:00", 'ends_at' => "2026-11-1{$i} 09:00:00"]);
    }
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = [$e->level, $e->message, $e->context];
    });

    $ics = IcalExporter::export($user, 3);

    expect(ics_events($ics))->toHaveCount(3)
        ->and(IcalExporter::MAX_EVENTS)->toBe(5000)
        ->and($logged)->toHaveCount(1)
        ->and($logged[0][0])->toBe('warning')
        ->and($logged[0][2])->toMatchArray(['user_id' => $user->id, 'limit' => 3]);
});
