<?php

namespace Modules\Calendar\Models;

use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Calendar\Support\Visibility;

/**
 * One event or the master row of a series (recurrence_rule set; no exceptions).
 *
 * Time storage:
 * - timed (`all_day` false): `starts_at`/`ends_at` are UTC instants; shown in config('ignis.timezone').
 * - all-day (`all_day` true): **dates**. Both columns hold 00:00:00 of the calendar day, `ends_at` is the last day
 *   (inclusive); the time part and every time zone are ignored: read them with ->toDateString(), never convert.
 *   A one-day event has starts_at == ends_at. `recurrence_until` follows the same rule (date of the last day).
 *
 * @property int $id
 * @property string $title
 * @property ?string $description plain text
 * @property ?string $location
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property bool $all_day
 * @property string $color
 * @property string $category
 * @property string $visibility private|attendees|role|all
 * @property bool $track_attendance
 * @property ?int $form_submission_id set = mirrored absence, read-only for everyone
 * @property ?int $created_by
 * @property ?string $recurrence_rule normalised subset, e.g. FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE;COUNT=10 (UNTIL lives in recurrence_until)
 * @property ?CarbonImmutable $recurrence_until
 * @property int|null $attendees_count
 */
class CalendarEvent extends Model
{
    public const COLORS = ['accent', 'blue', 'green', 'red', 'purple', 'gray'];

    public const CATEGORIES = ['general', 'meeting', 'training', 'duty', 'absence'];

    public const VISIBILITIES = ['private', 'attendees', 'role', 'all'];

    public const MAX_SERIES_DAYS = 31;

    protected $table = 'calendar_events';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'recurrence_until' => 'immutable_datetime',
            'all_day' => 'boolean',
            'track_attendance' => 'boolean',
        ];
    }

    /** @return HasMany<CalendarAttendee, $this> */
    public function attendees(): HasMany
    {
        return $this->hasMany(CalendarAttendee::class, 'event_id');
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'calendar_event_role', 'event_id', 'role_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isLocked(): bool
    {
        return $this->form_submission_id !== null;
    }

    /** @param Builder<CalendarEvent> $query */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        Visibility::scope($query, $user);
    }

    /**
     * Loose pre-filter for [$from, $to): single events overlapping it, series begun before its end and not finished
     * before it. All-day dates are 00:00 UTC but cover a day in the organisation's zone, hence a day of slack;
     * RecurrenceExpander decides exactly.
     * Invariant: an occurrence starting at UNTIL still shows while UNTIL + duration > from, so the
     * series bound is MAX_SERIES_DAYS + 1 day before the window. The duration cap is enforced in EventRequest (and must
     * be in the importer); a longer legacy row is expanded fine but may drop out of windows beyond that bound.
     *
     * @param  Builder<CalendarEvent>  $query
     */
    public function scopeTouching(Builder $query, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $lo = $from->utc()->subDay()->format('Y-m-d H:i:s');
        $seriesLo = $from->utc()->subDays(self::MAX_SERIES_DAYS + 1)->format('Y-m-d H:i:s');
        $query->where('calendar_events.starts_at', '<', $to->utc()->addDay()->format('Y-m-d H:i:s'))
            ->where(fn (Builder $q) => $q->where(fn (Builder $s) => $s->whereNotNull('calendar_events.recurrence_rule')
                ->where(fn (Builder $u) => $u->whereNull('calendar_events.recurrence_until')->orWhere('calendar_events.recurrence_until', '>=', $seriesLo)))
                ->orWhere('calendar_events.ends_at', '>=', $lo));
    }
}
