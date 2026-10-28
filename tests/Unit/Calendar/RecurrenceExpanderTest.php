<?php

use Carbon\CarbonImmutable;
use Modules\Calendar\Support\RecurrenceExpander;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(fn () => config(['ignis.timezone' => 'Europe/Berlin']));

function rx(array $event, string $from, string $to, int $limit = 1000): array
{
    $event += ['all_day' => false, 'recurrence_rule' => null, 'recurrence_until' => null];
    $f = $event['all_day'] ? 'Y-m-d' : 'Y-m-d H:i';

    return array_map(fn (array $o) => $o['start']->format($f), RecurrenceExpander::expand($event, CarbonImmutable::parse($from), CarbonImmutable::parse($to), $limit));
}

function rxd(string $start, ?string $rule, string $from, string $to, ?string $until = null): array
{
    return rx(['starts_at' => $start, 'ends_at' => $start, 'all_day' => true, 'recurrence_rule' => $rule, 'recurrence_until' => $until],
        $from.'T00:00:00 Europe/Berlin', $to.'T00:00:00 Europe/Berlin');
}

it('returns a single event only when it overlaps the window', function () {
    $e = ['starts_at' => '2026-01-10 09:00:00', 'ends_at' => '2026-01-10 11:00:00'];

    expect(rx($e, '2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z'))->toBe(['2026-01-10 09:00'])
        ->and(rx($e, '2026-01-10T10:00:00Z', '2026-01-11T00:00:00Z'))->toBe(['2026-01-10 09:00'])
        ->and(rx($e, '2026-01-10T11:00:00Z', '2026-01-11T00:00:00Z'))->toBe([])
        ->and(rx($e, '2026-01-01T00:00:00Z', '2026-01-10T09:00:00Z'))->toBe([]);
});

it('returns nothing for an empty or inverted window', function () {
    $e = ['starts_at' => '2026-01-10 09:00:00', 'ends_at' => '2026-01-10 11:00:00', 'recurrence_rule' => 'FREQ=DAILY'];

    expect(rx($e, '2026-01-10T09:00:00Z', '2026-01-10T09:00:00Z'))->toBe([])
        ->and(rx($e, '2026-01-12T00:00:00Z', '2026-01-11T00:00:00Z'))->toBe([]);
});

it('expands DAILY with and without INTERVAL', function () {
    expect(rxd('2026-01-01', 'FREQ=DAILY', '2026-01-01', '2026-01-05'))->toBe(['2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04'])
        ->and(rxd('2026-01-01', 'FREQ=DAILY;INTERVAL=3', '2026-01-01', '2026-01-12'))->toBe(['2026-01-01', '2026-01-04', '2026-01-07', '2026-01-10']);
});

it('repeats WEEKLY without BYDAY on the weekday of the start', function () {
    expect(rxd('2026-01-07', 'FREQ=WEEKLY', '2026-01-01', '2026-02-01'))->toBe(['2026-01-07', '2026-01-14', '2026-01-21', '2026-01-28']);
});

it('honours INTERVAL and BYDAY on WEEKLY', function () {
    expect(rxd('2026-01-05', 'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE', '2026-01-01', '2026-02-01'))
        ->toBe(['2026-01-05', '2026-01-07', '2026-01-19', '2026-01-21'])
        ->and(rxd('2026-01-07', 'FREQ=WEEKLY;BYDAY=MO,WE,FR', '2026-01-01', '2026-01-13'))->toBe(['2026-01-07', '2026-01-09', '2026-01-12']);
});

it('shows a series that began years ago in today\'s window', function () {
    expect(rxd('2019-03-04', 'FREQ=DAILY', '2026-10-21', '2026-10-24'))->toBe(['2026-10-21', '2026-10-22', '2026-10-23'])
        ->and(rx(['starts_at' => '2019-03-04 17:00:00', 'ends_at' => '2019-03-04 18:00:00', 'recurrence_rule' => 'FREQ=WEEKLY;INTERVAL=1'],
            '2026-10-19T00:00:00Z', '2026-10-26T00:00:00Z'))->toBe(['2026-10-19 16:00']);
});

it('skips months without the day on MONTHLY', function () {
    expect(rxd('2026-01-31', 'FREQ=MONTHLY', '2026-01-01', '2027-01-01'))
        ->toBe(['2026-01-31', '2026-03-31', '2026-05-31', '2026-07-31', '2026-08-31', '2026-10-31', '2026-12-31'])
        ->and(rxd('2026-01-31', 'FREQ=MONTHLY;INTERVAL=2', '2026-01-01', '2027-01-01'))->toBe(['2026-01-31', '2026-03-31', '2026-05-31', '2026-07-31'])
        ->and(rxd('2024-02-29', 'FREQ=MONTHLY;INTERVAL=12', '2024-01-01', '2029-01-01'))->toBe(['2024-02-29', '2028-02-29']);
});

it('counts COUNT from the series start, also when the window starts later', function () {
    expect(rxd('2026-01-01', 'FREQ=DAILY;COUNT=5', '2026-01-01', '2026-02-01'))->toHaveCount(5)
        ->and(rxd('2026-01-01', 'FREQ=DAILY;COUNT=5', '2026-01-03', '2026-02-01'))->toBe(['2026-01-03', '2026-01-04', '2026-01-05'])
        ->and(rxd('2026-01-07', 'FREQ=WEEKLY;BYDAY=MO,WE,FR;COUNT=4', '2026-01-13', '2026-03-01'))->toBe(['2026-01-14'])
        ->and(rxd('2026-01-31', 'FREQ=MONTHLY;COUNT=3', '2026-04-01', '2027-01-01'))->toBe(['2026-05-31'])
        ->and(rxd('2026-01-31', 'FREQ=MONTHLY;COUNT=3', '2026-06-01', '2027-01-01'))->toBe([]);
});

it('stops at UNTIL from the column or the rule, inclusive', function () {
    $e = ['starts_at' => '2026-01-01 09:00:00', 'ends_at' => '2026-01-01 10:00:00'];

    expect(rx($e + ['recurrence_rule' => 'FREQ=DAILY', 'recurrence_until' => '2026-01-03 09:00:00'], '2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z'))
        ->toBe(['2026-01-01 09:00', '2026-01-02 09:00', '2026-01-03 09:00'])
        ->and(rx($e + ['recurrence_rule' => 'FREQ=DAILY;UNTIL=20260102T090000Z'], '2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z'))->toHaveCount(2)
        ->and(rx($e + ['recurrence_rule' => 'FREQ=DAILY;UNTIL=2026-01-02'], '2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z'))->toHaveCount(2)
        ->and(rxd('2026-01-01', 'FREQ=DAILY', '2026-01-01', '2026-02-01', '2026-01-04 00:00:00'))->toHaveCount(4);
});

it('treats all-day events as dates across month and year boundaries', function () {
    $span = fn (string $from, string $to) => rx(['starts_at' => '2026-12-30 00:00:00', 'ends_at' => '2027-01-02 00:00:00', 'all_day' => true],
        $from.'T00:00:00 Europe/Berlin', $to.'T00:00:00 Europe/Berlin');

    expect($span('2027-01-01', '2027-02-01'))->toBe(['2026-12-30'])
        ->and($span('2027-01-02', '2027-01-03'))->toBe(['2026-12-30'])
        ->and($span('2027-01-03', '2027-02-01'))->toBe([])
        ->and(rxd('2026-01-31', null, '2026-02-01', '2026-03-01'))->toBe([])
        ->and(rxd('2026-03-01', null, '2026-02-01', '2026-03-01'))->toBe([])
        ->and(rxd('2026-02-01', null, '2026-02-01', '2026-03-01'))->toBe(['2026-02-01']);

    $o = RecurrenceExpander::expand(['starts_at' => '2026-01-30', 'ends_at' => '2026-02-01', 'all_day' => true, 'recurrence_rule' => 'FREQ=WEEKLY'],
        CarbonImmutable::parse('2026-02-07T00:00:00+01:00'), CarbonImmutable::parse('2026-02-08T00:00:00+01:00'));
    expect(array_map(fn ($x) => [$x['start']->toDateString(), $x['end']->toDateString()], $o))->toBe([['2026-02-06', '2026-02-08']]);
});

it('keeps the wall time across the DST changes in Europe/Berlin', function () {
    $spring = ['starts_at' => '2026-03-22 09:00:00', 'ends_at' => '2026-03-22 11:00:00', 'recurrence_rule' => 'FREQ=WEEKLY'];
    $o = RecurrenceExpander::expand($spring, CarbonImmutable::parse('2026-03-20T00:00:00Z'), CarbonImmutable::parse('2026-04-06T00:00:00Z'));
    expect(array_map(fn ($x) => $x['start']->format('m-d H:i').'/'.$x['end']->format('H:i'), $o))->toBe(['03-22 09:00/11:00', '03-29 08:00/10:00', '04-05 08:00/10:00']);

    $fall = ['starts_at' => '2026-10-24 08:00:00', 'ends_at' => '2026-10-24 09:00:00', 'recurrence_rule' => 'FREQ=DAILY'];
    expect(rx($fall, '2026-10-24T00:00:00Z', '2026-10-27T00:00:00Z'))->toBe(['2026-10-24 08:00', '2026-10-25 09:00', '2026-10-26 09:00']);
});

it('finds an occurrence that began before the window and runs into it', function () {
    $e = ['starts_at' => '2026-01-01 22:00:00', 'ends_at' => '2026-01-02 00:00:00', 'recurrence_rule' => 'FREQ=DAILY'];

    expect(rx($e, '2026-01-09T23:30:00Z', '2026-01-10T12:00:00Z'))->toBe(['2026-01-09 22:00']);
});

it('caps the occurrences per window', function () {
    expect(rxd('2026-01-01', 'FREQ=DAILY', '2026-01-01', '2027-01-01'))->toHaveCount(365)
        ->and(rx(['starts_at' => '2026-01-01', 'ends_at' => '2026-01-01', 'all_day' => true, 'recurrence_rule' => 'FREQ=DAILY'], '2026-01-01T00:00:00Z', '2027-01-01T00:00:00Z', 10))->toHaveCount(10);
});

it('falls back to the single event for a rule it does not understand', function () {
    expect(rxd('2026-01-05', 'FREQ=YEARLY', '2026-01-01', '2027-01-01'))->toBe(['2026-01-05'])
        ->and(RecurrenceExpander::parse('FREQ=WEEKLY;INTERVAL=2;BYDAY=WE,MO;COUNT=10'))->toBe(['freq' => 'WEEKLY', 'interval' => 2, 'byday' => [1, 3], 'count' => 10, 'until' => null])
        ->and(RecurrenceExpander::parse('FREQ=DAILY;BYDAY=MO')['byday'])->toBe([]);
});

it('matches a naive day-by-day expansion', function (string $start, string $rule, string $from, string $to) {
    $p = RecurrenceExpander::parse($rule);
    $s = CarbonImmutable::parse($start, 'UTC');
    $week0 = $s->subDays($s->dayOfWeekIso - 1);
    $byday = $p['byday'] ?: [$s->dayOfWeekIso];
    $expected = [];
    $n = 0;
    for ($d = $s; $d < CarbonImmutable::parse($to, 'UTC'); $d = $d->addDay()) {
        $hit = match ($p['freq']) {
            'DAILY' => (int) $s->diffInDays($d) % $p['interval'] === 0,
            'WEEKLY' => in_array($d->dayOfWeekIso, $byday, true) && intdiv((int) $week0->diffInDays($d), 7) % $p['interval'] === 0,
            'MONTHLY' => $d->day === $s->day && (($d->year - $s->year) * 12 + $d->month - $s->month) % $p['interval'] === 0,
        };
        if (! $hit) {
            continue;
        }
        if ($p['count'] !== null && ++$n > $p['count']) {
            break;
        }
        if ($d->toDateString() >= $from) {
            $expected[] = $d->toDateString();
        }
    }

    expect(rxd($start, $rule, $from, $to))->toBe($expected);
})->with([
    ['2019-02-13', 'FREQ=DAILY;INTERVAL=5', '2026-10-01', '2026-11-15'],
    ['2019-02-13', 'FREQ=WEEKLY;INTERVAL=3;BYDAY=TU,SA,SU', '2026-10-01', '2026-12-31'],
    ['2020-02-29', 'FREQ=MONTHLY', '2025-12-01', '2028-04-01'],
    ['2021-08-31', 'FREQ=MONTHLY;INTERVAL=5', '2024-01-01', '2027-01-01'],
    ['2026-01-04', 'FREQ=WEEKLY;BYDAY=MO,SU;COUNT=7', '2026-01-10', '2026-03-01'],
    ['2025-10-31', 'FREQ=MONTHLY;COUNT=6', '2026-03-01', '2027-03-01'],
    ['2025-06-30', 'FREQ=DAILY;INTERVAL=7;COUNT=40', '2026-01-01', '2026-06-01'],
]);

it('refuses impossible UNTIL values without throwing', function (string $until) {
    expect(RecurrenceExpander::ruleUntil($until, 'Europe/Berlin'))->toBeNull();
})->with(['20261345', '2026-02-30', '2026-13-45', '20261231T250000Z', '20261231T236000', 'x', '2026-12-31T120000Z']);

it('returns a single timed event exactly as stored, also in the repeated DST hour', function () {
    $o = RecurrenceExpander::expand(['starts_at' => '2026-10-25 01:30:00', 'ends_at' => '2026-10-25 02:00:00'],
        CarbonImmutable::parse('2026-10-25T00:00:00Z'), CarbonImmutable::parse('2026-10-26T00:00:00Z'));

    expect($o[0]['start']->format('H:i').'-'.$o[0]['end']->format('H:i'))->toBe('01:30-02:00');
});

it('expands legacy series rows longer than the 31-day cap without trouble', function () {
    $o = RecurrenceExpander::expand(['starts_at' => '2026-01-01', 'ends_at' => '2026-03-01', 'all_day' => true, 'recurrence_rule' => 'FREQ=MONTHLY;INTERVAL=1;COUNT=3'],
        CarbonImmutable::parse('2026-04-15T00:00:00Z'), CarbonImmutable::parse('2026-04-16T00:00:00Z'));

    expect(array_map(fn ($x) => $x['start']->toDateString(), $o))->toBe(['2026-03-01']);
});
