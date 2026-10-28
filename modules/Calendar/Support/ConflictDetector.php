<?php

namespace Modules\Calendar\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Calendar\Models\CalendarEvent;

/**
 * Non-blocking hint: other events the invited records attend (not declined) that overlap the event's first
 * occurrence. One query for all records; series of the other events are expanded. Only events the viewer may see are
 * reported, so the hint leaks nothing. Role/all events without an explicit row do not count (legacy).
 */
final class ConflictDetector
{
    /**
     * @param  list<int>  $personnelIds
     * @return list<array{personnel_id: int, name: string, events: list<array{id: int, title: string}>}>
     */
    public static function find(CalendarEvent $event, array $personnelIds, User $viewer): array
    {
        if ($personnelIds === []) {
            return [];
        }
        $zone = (string) config('ignis.timezone');
        [$from, $to] = $event->all_day
            ? [CarbonImmutable::parse($event->starts_at->toDateString(), $zone), CarbonImmutable::parse($event->ends_at->toDateString(), $zone)->addDay()]
            : [$event->starts_at, $event->ends_at->max($event->starts_at->addSecond())];

        $rows = CalendarEvent::query()->visibleTo($viewer)->touching($from, $to)
            ->join('calendar_attendees as a', 'a.event_id', '=', 'calendar_events.id')
            ->join('personnel as p', 'p.id', '=', 'a.personnel_id')
            ->whereIn('a.personnel_id', $personnelIds)
            ->where('a.response', '!=', 'declined')
            ->when($event->exists, fn ($q) => $q->where('calendar_events.id', '!=', $event->id))
            ->orderBy('calendar_events.starts_at')
            ->get(['calendar_events.*', 'a.personnel_id as conflict_personnel_id', 'p.full_name as conflict_name']);

        $out = [];
        foreach ($rows as $row) {
            if (RecurrenceExpander::expand($row, $from, $to, 1) === []) {
                continue;
            }
            $pid = (int) $row->getAttribute('conflict_personnel_id');
            $out[$pid] ??= ['personnel_id' => $pid, 'name' => (string) $row->getAttribute('conflict_name'), 'events' => []];
            $out[$pid]['events'][] = ['id' => $row->id, 'title' => $row->title];
        }

        return array_values($out);
    }
}
