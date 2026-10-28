<?php

namespace Modules\Calendar\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\Calendar\Models\CalendarEvent;

/**
 * Expands an event (or the master row of a series) into its occurrences inside [$from, $to).
 *
 * Subset: FREQ=DAILY|WEEKLY|MONTHLY, INTERVAL (all), BYDAY (WEEKLY only; default = weekday of the start),
 * COUNT (counted from the series start) or UNTIL (recurrence_until column, else UNTIL in the rule). No exceptions.
 * - The first period inside the window is found by arithmetic, never by walking from the series start
 *   (MONTHLY with COUNT counts the months before the window, bounded by COUNT).
 * - MONTHLY skips months that lack the start's day (no overflow into the next month, RFC 5545).
 * - Timed events repeat at the same wall time in config('ignis.timezone') (the UTC time moves with DST);
 *   all-day events are dates and never touch a time zone. A rule that is not understood yields the single event.
 *
 * Occurrences: timed => UTC instants; all-day => dates at 00:00 UTC, `end` = last day inclusive (see CalendarEvent).
 */
final class RecurrenceExpander
{
    public const DAYS = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

    /** ponytail: hard stop for pathological rules (e.g. Feb 29 every 12 months for centuries); the window ends far earlier. */
    private const MAX_PERIODS = 100000;

    /**
     * @param  CalendarEvent|array{starts_at: mixed, ends_at: mixed, all_day?: bool, recurrence_rule?: ?string, recurrence_until?: mixed}  $event
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    public static function expand(CalendarEvent|array $event, CarbonImmutable $from, CarbonImmutable $to, int $limit = 1000): array
    {
        if ($event instanceof CalendarEvent) {
            $event = ['starts_at' => $event->starts_at, 'ends_at' => $event->ends_at, 'all_day' => $event->all_day,
                'recurrence_rule' => $event->recurrence_rule, 'recurrence_until' => $event->recurrence_until];
        }
        $zone = (string) config('ignis.timezone');
        $allDay = (bool) ($event['all_day'] ?? false);
        $start = self::instant($event['starts_at']);
        $end = self::instant($event['ends_at']);
        if ($end < $start) {
            $end = $start;
        }
        $local = $allDay ? $start->startOfDay() : $start->setTimezone($zone);
        $startDate = CarbonImmutable::create($local->year, $local->month, $local->day, 0, 0, 0, 'UTC');
        $days = $allDay ? (int) $start->startOfDay()->diffInDays($end->startOfDay()) : 0;
        $seconds = $end->getTimestamp() - $start->getTimestamp();

        $occurrence = fn (CarbonImmutable $date): array => $allDay
            ? ['start' => $date, 'end' => $date->addDays($days)]
            : ['start' => $s = CarbonImmutable::create($date->year, $date->month, $date->day, $local->hour, $local->minute, $local->second, $zone)->utc(),
                'end' => $s->addSeconds($seconds)];
        $span = fn (array $o): array => $allDay
            ? [CarbonImmutable::parse($o['start']->toDateString(), $zone), CarbonImmutable::parse($o['end']->toDateString(), $zone)->addDay()]
            : [$o['start'], $o['end']];
        $overlaps = function (array $o) use ($span, $from, $to): bool {
            [$s, $e] = $span($o);

            return $s < $to && ($e > $from || ($s == $e && $s >= $from));
        };

        $rule = self::parse($event['recurrence_rule'] ?? null);
        if ($rule === null || $limit < 1 || $from >= $to) {
            $single = $allDay ? $occurrence($startDate) : ['start' => $start, 'end' => $end];

            return $limit >= 1 && $from < $to && $overlaps($single) ? [$single] : [];
        }

        $untilValue = $event['recurrence_until'] ?? null;
        $until = $untilValue !== null && $untilValue !== '' ? self::instant($untilValue) : self::ruleUntil($rule['until'], $allDay ? 'UTC' : $zone);
        $interval = $rule['interval'];
        $count = $rule['count'];

        $jump = $from->setTimezone($allDay ? 'UTC' : $zone);
        $jump = CarbonImmutable::create($jump->year, $jump->month, $jump->day, 0, 0, 0, 'UTC')
            ->subDays(($allDay ? $days : intdiv($seconds, 86400)) + 2);
        $diffDays = $jump > $startDate ? (int) $startDate->diffInDays($jump) : 0;

        switch ($rule['freq']) {
            case 'DAILY':
                $m0 = intdiv($diffDays, $interval);
                $before = $m0;
                $candidates = fn (int $m): array => [$startDate->addDays($m * $interval)];
                break;
            case 'WEEKLY':
                $byDay = $rule['byday'] ?: [$startDate->dayOfWeekIso];
                $week0 = $startDate->subDays($startDate->dayOfWeekIso - 1);
                $m0 = intdiv(intdiv($jump > $week0 ? (int) $week0->diffInDays($jump) : 0, 7), $interval);
                $firstWeek = count(array_filter($byDay, fn (int $d) => $d >= $startDate->dayOfWeekIso));
                $before = $m0 === 0 ? 0 : $firstWeek + ($m0 - 1) * count($byDay);
                $candidates = fn (int $m): array => array_values(array_filter(
                    array_map(fn (int $d) => $week0->addDays($m * $interval * 7 + $d - 1), $byDay),
                    fn (CarbonImmutable $d) => $d >= $startDate,
                ));
                break;
            default: // MONTHLY
                $base = $startDate->year * 12 + $startDate->month - 1;
                $months = max(0, $jump->year * 12 + $jump->month - 1 - $base);
                $m0 = intdiv($months, $interval);
                $candidates = function (int $m) use ($base, $interval, $startDate): array {
                    $index = $base + $m * $interval;
                    $year = intdiv($index, 12);
                    $month = $index % 12 + 1;

                    return $startDate->day <= CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC')->daysInMonth
                        ? [CarbonImmutable::create($year, $month, $startDate->day, 0, 0, 0, 'UTC')] : [];
                };
                $before = 0;
                if ($count !== null) {
                    for ($m = 0; $m < $m0 && $before < $count; $m++) {
                        $before += count($candidates($m));
                    }
                }
        }

        $out = [];
        $ordinal = $before;
        for ($m = $m0, $guard = 0; $guard < self::MAX_PERIODS; $m++, $guard++) {
            foreach ($candidates($m) as $date) {
                if ($count !== null && $ordinal >= $count) {
                    break 2;
                }
                $ordinal++;
                $o = $occurrence($date);
                if ($until !== null && ($allDay ? $date->toDateString() > $until->toDateString() : $o['start'] > $until)) {
                    break 2;
                }
                if ($span($o)[0] >= $to) {
                    break 2;
                }
                if ($overlaps($o)) {
                    $out[] = $o;
                    if (count($out) >= $limit) {
                        break 2;
                    }
                }
            }
            if ($count !== null && $ordinal >= $count) {
                break;
            }
        }

        return $out;
    }

    /**
     * The rule as stored or imported (`KEY=VALUE;…`, any order); null when it is not the supported subset.
     *
     * @return array{freq: string, interval: int, byday: list<int>, count: ?int, until: ?string}|null
     */
    public static function parse(?string $rule): ?array
    {
        if ($rule === null || trim($rule) === '') {
            return null;
        }
        $parts = [];
        foreach (explode(';', strtoupper(trim($rule))) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }
        $freq = $parts['FREQ'] ?? '';
        if (! in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY'], true)) {
            return null;
        }
        $interval = isset($parts['INTERVAL']) && ctype_digit($parts['INTERVAL']) && (int) $parts['INTERVAL'] > 0 ? (int) $parts['INTERVAL'] : 1;
        $byday = [];
        if ($freq === 'WEEKLY' && ($parts['BYDAY'] ?? '') !== '') {
            foreach (explode(',', $parts['BYDAY']) as $d) {
                if (isset(self::DAYS[trim($d)])) {
                    $byday[] = self::DAYS[trim($d)];
                }
            }
            $byday = array_values(array_unique($byday));
            sort($byday);
        }
        $count = isset($parts['COUNT']) && ctype_digit($parts['COUNT']) && (int) $parts['COUNT'] > 0 ? (int) $parts['COUNT'] : null;

        return ['freq' => $freq, 'interval' => $interval, 'byday' => $byday, 'count' => $count, 'until' => $parts['UNTIL'] ?? null];
    }

    public static function ruleUntil(?string $value, string $zone): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})$/', $value, $m) === 1) {
            $time = [23, 59, 59];
            $tz = $zone;
        } elseif (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/', $value, $m) === 1) {
            $time = [(int) $m[4], (int) $m[5], (int) $m[6]];
            $tz = $m[7] === 'Z' ? 'UTC' : $zone;
        } else {
            return null;
        }
        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || $time[0] > 23 || $time[1] > 59 || $time[2] > 59) {
            return null;
        }
        try {
            return CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], $time[0], $time[1], $time[2], $tz)?->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function instant(mixed $value): CarbonImmutable
    {
        return $value instanceof DateTimeInterface ? CarbonImmutable::instance($value)->utc() : CarbonImmutable::parse((string) $value, 'UTC');
    }
}
