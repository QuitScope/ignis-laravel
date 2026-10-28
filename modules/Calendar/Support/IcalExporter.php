<?php

namespace Modules\Calendar\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Modules\Calendar\Models\CalendarEvent;

/**
 * The account's calendar as RFC 5545 text: events from 6 months back to 12 months ahead, the
 * same visibility and window rule as the page (Visibility, touching() + RecurrenceExpander), one VEVENT per event.
 * - single timed events: UTC (`…Z`); all-day: `VALUE=DATE`, DTEND exclusive (+1 day);
 * - series: the master with an RRULE rebuilt from the parsed rule (never the stored string), UNTIL as
 *   `YYYYMMDDTHHMMSSZ` (timed) or `YYYYMMDD` (all-day). Timed series repeat at the wall time of
 *   config('ignis.timezone') like the page, so their DTSTART/DTEND carry that TZID with one VTIMEZONE: a UTC DTSTART
 *   would move them by an hour (and BYDAY by a day near midnight) across daylight saving time in every client;
 * - no attendees or e-mail addresses; mirrored absences without description.
 */
final class IcalExporter
{
    public const MAX_EVENTS = 5000;

    public static function export(User $user, int $limit = self::MAX_EVENTS): string
    {
        $now = CarbonImmutable::now('UTC');
        $from = $now->subMonths(6);
        $to = $now->addMonths(12);
        $zone = (string) config('ignis.timezone');
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

        $events = [];
        $needsZone = false;
        $count = 0;
        $query = CalendarEvent::query()->visibleTo($user)->touching($from, $to)
            ->select(['id', 'title', 'description', 'location', 'starts_at', 'ends_at', 'all_day', 'category', 'form_submission_id',
                'recurrence_rule', 'recurrence_until', 'created_at', 'updated_at'])
            ->orderBy('starts_at')->orderBy('id');
        foreach ($query->cursor() as $event) {
            if (RecurrenceExpander::expand($event, $from, $to, 1) === []) {
                continue;
            }
            if ($count === $limit) {
                Log::warning('iCal feed truncated', ['user_id' => $user->id, 'limit' => $limit]);
                break;
            }
            $count++;
            $rule = RecurrenceExpander::parse($event->recurrence_rule);
            $needsZone = $needsZone || ($rule !== null && ! $event->all_day);
            array_push($events, ...self::event($event, $rule, $zone, $host));
        }

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//ignis//Calendar//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::text((string) config('app.name')),
            ...($needsZone ? self::timezone($zone) : []),
            ...$events,
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /**
     * @param  array{freq: string, interval: int, byday: list<int>, count: ?int, until: ?string}|null  $rule
     * @return list<string>
     */
    private static function event(CalendarEvent $e, ?array $rule, string $zone, string $host): array
    {
        $stamp = $e->updated_at ?? $e->created_at ?? CarbonImmutable::now();
        $out = ['BEGIN:VEVENT', 'UID:cal-'.$e->id.'@'.$host, 'DTSTAMP:'.CarbonImmutable::instance($stamp)->utc()->format('Ymd\THis\Z')];

        if ($e->all_day) {
            $start = $e->starts_at->toDateString();
            $last = max($start, $e->ends_at->toDateString());
            $out[] = 'DTSTART;VALUE=DATE:'.str_replace('-', '', $start);
            $out[] = 'DTEND;VALUE=DATE:'.CarbonImmutable::parse($last, 'UTC')->addDay()->format('Ymd');
        } elseif ($rule !== null) {
            $out[] = 'DTSTART;TZID='.$zone.':'.$e->starts_at->setTimezone($zone)->format('Ymd\THis');
            if ($e->ends_at > $e->starts_at) {
                $out[] = 'DTEND;TZID='.$zone.':'.$e->ends_at->setTimezone($zone)->format('Ymd\THis');
            }
        } else {
            $out[] = 'DTSTART:'.$e->starts_at->utc()->format('Ymd\THis\Z');
            if ($e->ends_at > $e->starts_at) {
                $out[] = 'DTEND:'.$e->ends_at->utc()->format('Ymd\THis\Z');
            }
        }

        if ($rule !== null) {
            $parts = ['FREQ='.$rule['freq'], 'INTERVAL='.$rule['interval']];
            if ($rule['byday'] !== []) {
                $days = array_flip(RecurrenceExpander::DAYS);
                $parts[] = 'BYDAY='.implode(',', array_map(fn (int $d) => $days[$d], $rule['byday']));
            }
            // RFC 5545 allows only one of COUNT and UNTIL; validation never stores both (a legacy row with both keeps COUNT).
            $until = $e->recurrence_until ?? RecurrenceExpander::ruleUntil($rule['until'], $e->all_day ? 'UTC' : $zone);
            if ($rule['count'] !== null) {
                $parts[] = 'COUNT='.$rule['count'];
            } elseif ($until !== null) {
                $parts[] = 'UNTIL='.($e->all_day ? $until->format('Ymd') : $until->utc()->format('Ymd\THis\Z'));
            }
            $out[] = 'RRULE:'.implode(';', $parts);
        }

        $out[] = 'SUMMARY:'.self::text($e->title);
        if ($e->location !== null && $e->location !== '') {
            $out[] = 'LOCATION:'.self::text($e->location);
        }
        if (! $e->isLocked() && $e->description !== null && $e->description !== '') {
            $out[] = 'DESCRIPTION:'.self::text($e->description);
        }
        $out[] = 'CATEGORIES:'.self::text(trans('calendar.category.'.$e->category));
        $out[] = 'STATUS:CONFIRMED';
        $out[] = 'END:VEVENT';

        return $out;
    }

    /**
     * VTIMEZONE for $zone from this year's transitions: two yearly rules (DST zones whose switch is "the n-th / last
     * weekday of a month", as in Europe and North America) or one fixed offset.
     * ponytail: zones with other switch rules get this year's switch dates as a yearly approximation.
     *
     * @return list<string>
     */
    private static function timezone(string $zone): array
    {
        $year = CarbonImmutable::now('UTC')->year;
        $all = (new DateTimeZone($zone))->getTransitions(gmmktime(0, 0, 0, 1, 1, $year), gmmktime(0, 0, 0, 1, 1, $year + 1)) ?: [];
        $offset = fn (int $seconds): string => ($seconds < 0 ? '-' : '+').gmdate('Hi', abs($seconds));
        $lines = ['BEGIN:VTIMEZONE', 'TZID:'.$zone];
        $changes = array_slice($all, 1);

        if (count($changes) !== 2) {
            $now = $all[0] ?? ['offset' => 0, 'abbr' => 'UTC'];

            return [...$lines, 'BEGIN:STANDARD', 'DTSTART:19700101T000000', 'TZOFFSETFROM:'.$offset($now['offset']),
                'TZOFFSETTO:'.$offset($now['offset']), 'TZNAME:'.self::text($now['abbr']), 'END:STANDARD', 'END:VTIMEZONE'];
        }
        foreach ($changes as $i => $t) {
            $before = $changes[1 - $i]['offset'];
            $local = CarbonImmutable::createFromTimestampUTC($t['ts'] + $before);
            $nth = $local->day > $local->daysInMonth - 7 ? -1 : intdiv($local->day - 1, 7) + 1;
            $first = CarbonImmutable::create(1970, $local->month, 1, 0, 0, 0, 'UTC');
            $start = $nth === -1 ? $first->lastOfMonth($local->dayOfWeek) : $first->nthOfMonth($nth, $local->dayOfWeek);
            $type = $t['isdst'] ? 'DAYLIGHT' : 'STANDARD';
            array_push($lines, 'BEGIN:'.$type, 'DTSTART:'.$start->format('Ymd').'T'.$local->format('His'),
                'RRULE:FREQ=YEARLY;BYMONTH='.$local->month.';BYDAY='.$nth.strtoupper(substr($local->englishDayOfWeek, 0, 2)),
                'TZOFFSETFROM:'.$offset($before), 'TZOFFSETTO:'.$offset($t['offset']), 'TZNAME:'.self::text($t['abbr']), 'END:'.$type);
        }
        $lines[] = 'END:VTIMEZONE';

        return $lines;
    }

    /** RFC 5545 TEXT: backslash, `;` and `,` escaped, line breaks as `\n`, other control characters and lone CR dropped. */
    public static function text(string $value): string
    {
        $value = str_replace("\r\n", "\n", mb_scrub($value, 'UTF-8'));
        $value = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $value);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $value);
    }

    public static function fold(string $line): string
    {
        $out = '';
        $max = 75;
        while (strlen($line) > $max) {
            $cut = $max;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out .= substr($line, 0, $cut)."\r\n ";
            $line = substr($line, $cut);
            $max = 74;
        }

        return $out.$line;
    }
}
