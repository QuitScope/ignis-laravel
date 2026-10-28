<?php

namespace Modules\Calendar\Support;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Calendar\Models\CalendarEvent;

/**
 * Who an event is for: explicit attendee rows (attendees/private/all), or the members of its roles (role events are
 * never written out per member; a row appears only when a member answers with attendance tracking on).
 * Role membership = spatie model_has_roles of active accounts (an account may hold several roles).
 */
final class AttendeeResolver
{
    /**
     * Accounts to notify: role events only with attendance tracking (then every member), attendees/all
     * the invited records' accounts, private nobody (only the creator sees it). The creator is never among them.
     *
     * @return list<int> user ids
     */
    public static function notifyUserIds(CalendarEvent $event): array
    {
        $ids = match ($event->visibility) {
            'role' => $event->track_attendance ? self::members($event->roles()->pluck('roles.id')->all())->distinct()->pluck('u.id')->all() : [],
            'attendees', 'all' => User::query()->where('is_active', true)
                ->whereIn('personnel_id', $event->attendees()->select('personnel_id'))->pluck('id')->all(),
            default => [],
        };

        return array_values(array_diff(array_map('intval', $ids), [(int) $event->created_by]));
    }

    /**
     * Personnel ids of the members of each role, in one query (feed counts and the role attendee list).
     *
     * @param  list<int>  $roleIds
     * @return array<int, list<int>> role id => personnel ids
     */
    public static function rolePersonnel(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }
        $map = [];
        foreach (self::members($roleIds)->whereNotNull('u.personnel_id')->get(['mr.role_id', 'u.personnel_id']) as $row) {
            $map[(int) $row->role_id][] = (int) $row->personnel_id;
        }

        return $map;
    }

    /** @param list<int> $roleIds */
    private static function members(array $roleIds): Builder
    {
        return DB::table(config('permission.table_names.model_has_roles').' as mr')
            ->join('users as u', 'u.id', '=', 'mr.model_id')
            ->where('mr.model_type', (new User)->getMorphClass())
            ->whereIn('mr.role_id', $roleIds)
            ->where('u.is_active', true);
    }
}
