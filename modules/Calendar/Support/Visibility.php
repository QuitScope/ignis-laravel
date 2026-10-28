<?php

namespace Modules\Calendar\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Calendar\Models\CalendarEvent;

/**
 * Who sees an event, one rule for the feed, the detail, the policy and conflicts:
 * nobody without calendar.view; admin/calendar.manage (and super admins) everything; else the creator always,
 * `all` everyone, `role` when ANY role of the account is attached, `attendees` when the account's record is invited,
 * `private` the creator only.
 */
final class Visibility
{
    /** @param Builder<CalendarEvent> $query */
    public static function scope(Builder $query, User $user): void
    {
        if (! $user->hasPermission(['admin', 'calendar.view', 'calendar.manage'])) {
            $query->whereRaw('1 = 0');

            return;
        }
        if (self::seesAll($user)) {
            return;
        }
        $roleIds = $user->roles->pluck('id')->all();
        $personnelId = $user->personnel_id;

        $query->where(function (Builder $q) use ($user, $roleIds, $personnelId) {
            $q->where('calendar_events.created_by', $user->id)->orWhere('calendar_events.visibility', 'all');
            if ($roleIds !== []) {
                $q->orWhere(fn (Builder $r) => $r->where('calendar_events.visibility', 'role')
                    ->whereIn('calendar_events.id', fn (QueryBuilder $s) => $s->select('event_id')->from('calendar_event_role')->whereIn('role_id', $roleIds)));
            }
            if ($personnelId !== null) {
                $q->orWhere(fn (Builder $a) => $a->where('calendar_events.visibility', 'attendees')
                    ->whereIn('calendar_events.id', fn (QueryBuilder $s) => $s->select('event_id')->from('calendar_attendees')->where('personnel_id', $personnelId)));
            }
        });
    }

    public static function seesAll(User $user): bool
    {
        return $user->hasPermission(['admin', 'calendar.manage']);
    }

    public static function allows(User $user, CalendarEvent $event): bool
    {
        return CalendarEvent::query()->whereKey($event->id)->visibleTo($user)->exists();
    }
}
