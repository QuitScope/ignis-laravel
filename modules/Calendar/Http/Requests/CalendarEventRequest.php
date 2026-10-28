<?php

namespace Modules\Calendar\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Calendar\Models\CalendarEvent;
use Modules\Calendar\Support\RecurrenceExpander;

/**
 * Create and update of an event. Only these fields are read; created_by, form_submission_id and the organizer never
 * come from the body.
 *
 * Times: all-day => `YYYY-MM-DD` (dates, end inclusive); timed => ISO `YYYY-MM-DDTHH:MM[:SS]` with `Z`/offset, or
 * without one as wall time in config('ignis.timezone'). Series: the subset FREQ=DAILY|WEEKLY|MONTHLY;INTERVAL=n;
 * BYDAY=..(WEEKLY);COUNT=n|UNTIL=..; an UNTIL in the rule moves into recurrence_until.
 */
class CalendarEventRequest extends FormRequest
{
    public const RRULE = '/^FREQ=(DAILY|WEEKLY|MONTHLY)(;INTERVAL=[1-9]\d{0,2})?(;BYDAY=(MO|TU|WE|TH|FR|SA|SU)(,(MO|TU|WE|TH|FR|SA|SU)){0,6})?(;COUNT=[1-9]\d{0,3}|;UNTIL=(\d{4}-\d{2}-\d{2}|\d{8}(T\d{6}Z?)?))?$/';

    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';

    private const DATETIME = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/';

    private const UNTIL = '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$/';

    public function authorize(): bool
    {
        $event = $this->route('event');
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        if (! $event instanceof CalendarEvent) {
            return $user->can('create', CalendarEvent::class);
        }
        // An event the account cannot see answers like a missing one (no id oracle); a mirrored absence is read-only
        // for everyone, super admins included (they pass the policy through Gate::before).
        abort_unless($user->can('view', $event), 404);
        abort_if($event->isLocked(), 403);

        return $user->can('update', $event);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $time = ['required', 'string', 'regex:'.($this->boolean('all_day') ? self::DATE : self::DATETIME), 'date'];

        return [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:255'],
            'all_day' => ['sometimes', 'boolean'],
            'starts_at' => $time,
            'ends_at' => $time,
            'color' => ['sometimes', 'string', Rule::in(CalendarEvent::COLORS)],
            'category' => ['sometimes', 'string', Rule::in(CalendarEvent::CATEGORIES)],
            'visibility' => ['required', 'string', Rule::in(CalendarEvent::VISIBILITIES)],
            'track_attendance' => ['sometimes', 'boolean'],
            'role_ids' => [Rule::requiredIf($this->input('visibility') === 'role'), 'array', 'max:100'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
            'attendee_ids' => ['sometimes', 'array', 'max:500'],
            'attendee_ids.*' => ['integer', 'distinct', Rule::exists('personnel', 'id')
                ->where(fn ($q) => $q->whereIn('rank_id', DB::table('ranks')->where('is_archive', false)->select('id')))],
            'recurrence_rule' => ['nullable', 'string', 'max:255', 'regex:'.self::RRULE],
            'recurrence_until' => ['nullable', 'string', 'regex:'.self::UNTIL, 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['title', 'description', 'location', 'recurrence_rule', 'recurrence_until'] as $key) {
            if (is_string($this->input($key))) {
                $value = trim($this->input($key));
                $this->merge([$key => $value === '' && $key !== 'title' ? null : ($key === 'recurrence_rule' ? strtoupper($value) : $value)]);
            }
        }
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $rule = RecurrenceExpander::parse($this->input('recurrence_rule'));
            if ($rule !== null && $rule['until'] !== null && RecurrenceExpander::ruleUntil($rule['until'], 'UTC') === null) {
                $v->errors()->add('recurrence_rule', trans('calendar.validation.until_invalid'));

                return;
            }
            $d = $this->event();
            if ($d['ends_at'] < $d['starts_at']) {
                $v->errors()->add('ends_at', trans('calendar.validation.end_before_start'));
            }
            if ($rule !== null && $rule['freq'] !== 'WEEKLY' && str_contains((string) $this->input('recurrence_rule'), 'BYDAY')) {
                $v->errors()->add('recurrence_rule', trans('calendar.validation.byday_weekly'));
            }
            if ($rule !== null && $rule['count'] !== null && $this->input('recurrence_until') !== null) {
                $v->errors()->add('recurrence_until', trans('calendar.validation.count_and_until'));
            }
            if ($rule !== null && $rule['freq'] === 'WEEKLY' && $rule['byday'] !== []) {
                $start = CarbonImmutable::parse($d['starts_at'], 'UTC');
                $weekday = ($d['all_day'] ? $start : $start->setTimezone((string) config('ignis.timezone')))->dayOfWeekIso;
                if (! in_array($weekday, $rule['byday'], true)) {
                    $v->errors()->add('recurrence_rule', trans('calendar.validation.byday_start'));
                }
            }
            $span = CarbonImmutable::parse($d['starts_at'], 'UTC')->diffInSeconds(CarbonImmutable::parse($d['ends_at'], 'UTC')->addDays($d['all_day'] ? 1 : 0));
            if ($rule !== null && $span > CalendarEvent::MAX_SERIES_DAYS * 86400) {
                $v->errors()->add('ends_at', trans('calendar.validation.series_too_long', ['days' => (string) CalendarEvent::MAX_SERIES_DAYS]));
            }
            if ($d['recurrence_until'] !== null && $d['recurrence_until'] < $d['starts_at']) {
                $v->errors()->add('recurrence_until', trans('calendar.validation.until_before_start'));
            }
        }];
    }

    /**
     * Column values (times as 'Y-m-d H:i:s' UTC, dates as 'Y-m-d 00:00:00').
     *
     * @return array<string, mixed>
     */
    public function event(): array
    {
        $allDay = $this->boolean('all_day');
        $visibility = (string) $this->input('visibility');
        $rule = RecurrenceExpander::parse($this->input('recurrence_rule'));
        $starts = $this->time((string) $this->input('starts_at'), $allDay);
        $until = null;
        if ($rule !== null) {
            $field = $this->input('recurrence_until');
            $until = $field !== null ? $this->until((string) $field, $allDay) : $this->ruleUntil($rule['until'], $allDay);
        }

        return [
            'title' => (string) $this->input('title'),
            'description' => $this->input('description'),
            'location' => $this->input('location'),
            'all_day' => $allDay,
            'starts_at' => $starts,
            'ends_at' => $this->time((string) $this->input('ends_at'), $allDay),
            'color' => $this->input('color', 'accent'),
            'category' => $this->input('category', 'general'),
            'visibility' => $visibility,
            'track_attendance' => $visibility === 'role' && $this->boolean('track_attendance'),
            'recurrence_rule' => $rule === null ? null : self::normalise($rule),
            'recurrence_until' => $until,
        ];
    }

    /** @return list<int> */
    public function roleIds(): array
    {
        return $this->input('visibility') === 'role' ? array_values(array_map('intval', (array) $this->input('role_ids', []))) : [];
    }

    /** @return list<int> explicit invitations: only attendees/private events keep a list */
    public function attendeeIds(): array
    {
        return in_array($this->input('visibility'), ['attendees', 'private'], true) ? array_values(array_map('intval', (array) $this->input('attendee_ids', []))) : [];
    }

    /** @param array{freq: string, interval: int, byday: list<int>, count: ?int, until: ?string} $rule */
    public static function normalise(array $rule): string
    {
        $days = array_flip(RecurrenceExpander::DAYS);

        return 'FREQ='.$rule['freq'].';INTERVAL='.$rule['interval']
            .($rule['freq'] === 'WEEKLY' && $rule['byday'] !== [] ? ';BYDAY='.implode(',', array_map(fn (int $d) => $days[$d], $rule['byday'])) : '')
            .($rule['count'] !== null ? ';COUNT='.$rule['count'] : '');
    }

    private function time(string $value, bool $allDay): string
    {
        if ($allDay) {
            return substr($value, 0, 10).' 00:00:00';
        }
        $hasZone = preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value) === 1;

        return CarbonImmutable::parse($value, $hasZone ? null : (string) config('ignis.timezone'))->utc()->format('Y-m-d H:i:s');
    }

    private function until(string $value, bool $allDay): string
    {
        if ($allDay) {
            return substr($value, 0, 10).' 00:00:00';
        }
        $zone = (string) config('ignis.timezone');

        return (strlen($value) === 10 ? CarbonImmutable::parse($value, $zone)->endOfDay() : CarbonImmutable::parse($value, $zone))->utc()->format('Y-m-d H:i:s');
    }

    private function ruleUntil(?string $value, bool $allDay): ?string
    {
        $instant = RecurrenceExpander::ruleUntil($value, $allDay ? 'UTC' : (string) config('ignis.timezone'));

        return $instant === null ? null : ($allDay ? $instant->toDateString().' 00:00:00' : $instant->format('Y-m-d H:i:s'));
    }
}
