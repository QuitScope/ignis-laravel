<?php

namespace Modules\Calendar\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InboxNotification;
use App\Support\AuditLogger;
use App\Support\SqlLike;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Calendar\Http\Requests\CalendarEventRequest;
use Modules\Calendar\Models\CalendarAttendee;
use Modules\Calendar\Models\CalendarEvent;
use Modules\Calendar\Support\AttendeeResolver;
use Modules\Calendar\Support\ConflictDetector;
use Modules\Calendar\Support\RecurrenceExpander;
use Modules\Calendar\Support\Visibility;

/**
 * Calendar page, JSON feed (FullCalendar), detail, create/update/delete and responses.
 * Every route with an id answers 404 for an event the account may not see (detail, feed, update, delete, response), so
 * ids are no oracle; 403 only for visible events the account may not change.
 */
class CalendarController extends Controller
{
    private const MODULE = 'Kalender';

    private const MAX_WINDOW_DAYS = 400;

    private const NOTIFY_FIELDS = ['title', 'location', 'starts_at', 'ends_at'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CalendarEvent::class);
        $user = $request->user();
        $event = $request->query('event');

        return Inertia::render('Calendar/Index', [
            'can' => ['create' => $user->can('create', CalendarEvent::class), 'manage' => Visibility::seesAll($user)],
            'roles' => Role::query()->orderBy('priority')->orderBy('name')->get(['id', 'name'])->map(fn (Role $r) => ['id' => $r->id, 'name' => $r->name])->all(),
            'timezone' => (string) config('ignis.timezone'),
            'options' => ['colors' => CalendarEvent::COLORS, 'categories' => CalendarEvent::CATEGORIES, 'visibilities' => CalendarEvent::VISIBILITIES],
            'openNew' => $user->can('create', CalendarEvent::class) && ($request->boolean('new') || $request->is('calendar/create')),
            'openEvent' => is_string($event) && ctype_digit($event) && strlen($event) < 10 ? (int) $event : null,
        ]);
    }

    public function personnel(Request $request): JsonResponse
    {
        $this->authorize('lookup', CalendarEvent::class);
        $q = $request->query('q');
        $q = is_string($q) ? mb_substr(trim($q), 0, 100) : '';

        return response()->json(Personnel::query()->active()
            ->when($q !== '', fn ($query) => SqlLike::anyOf($query, ['full_name'], $q))
            ->orderBy('full_name')->orderBy('id')->limit(20)->get(['id', 'full_name'])
            ->map(fn (Personnel $p) => ['id' => $p->id, 'name' => $p->full_name])->all());
    }

    public function events(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CalendarEvent::class);
        [$from, $to] = $this->window($request);
        $user = $request->user();

        $events = CalendarEvent::query()->visibleTo($user)->touching($from, $to)
            ->select(['id', 'title', 'location', 'starts_at', 'ends_at', 'all_day', 'color', 'category', 'visibility', 'track_attendance',
                'form_submission_id', 'created_by', 'recurrence_rule', 'recurrence_until'])
            ->withCount('attendees')->with('roles:id')
            ->get();

        $members = AttendeeResolver::rolePersonnel($events->where('visibility', 'role')->flatMap(fn (CalendarEvent $e) => $e->roles->pluck('id'))->unique()->values()->all());
        $mine = $user->personnel_id === null || $events->isEmpty() ? collect()
            : CalendarAttendee::query()->where('personnel_id', $user->personnel_id)->whereIn('event_id', $events->pluck('id'))->pluck('response', 'event_id');

        $items = [];
        foreach ($events as $e) {
            $count = match ($e->visibility) {
                'role' => count(array_unique(array_merge([], ...array_map(fn (int $id) => $members[$id] ?? [], $e->roles->pluck('id')->all())))),
                'all' => null,
                default => (int) $e->attendees_count,
            };
            $base = [
                'id' => $e->id, 'all_day' => $e->all_day, 'title' => $e->title, 'color' => $e->color, 'category' => $e->category,
                'location' => $e->location, 'attendee_count' => $count, 'my_response' => $mine[$e->id] ?? null,
                'can_edit' => $this->canWrite($user, $e), 'recurring' => $e->recurrence_rule !== null,
                'source_locked' => $e->isLocked(), 'visibility' => $e->visibility,
            ];
            foreach (RecurrenceExpander::expand($e, $from, $to) as $o) {
                $items[] = ['occurrence_start' => $this->format($o['start'], $e->all_day), 'occurrence_end' => $this->format($o['end'], $e->all_day)] + $base;
            }
        }
        usort($items, fn (array $a, array $b) => [$a['occurrence_start'], $a['id']] <=> [$b['occurrence_start'], $b['id']]);

        return response()->json($items);
    }

    public function show(Request $request, CalendarEvent $event): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('view', $event), 404);
        $event->load(['roles:id,name', 'creator.personnel:id,full_name']);
        $rows = $event->attendees()->with('personnel:id,full_name')->get();
        $mine = $user->personnel_id === null ? null : $rows->firstWhere('personnel_id', $user->personnel_id);
        $roleIds = $event->roles->pluck('id')->all();
        $tracked = $event->visibility === 'role' && $event->track_attendance;
        $isMember = $tracked && array_intersect($roleIds, $user->roles->pluck('id')->all()) !== [];

        $attendees = null;
        if (in_array($event->visibility, ['attendees', 'private'], true)) {
            $attendees = $rows->filter(fn (CalendarAttendee $a) => $a->personnel !== null)
                ->map(fn (CalendarAttendee $a) => ['personnel_id' => $a->personnel_id, 'name' => $a->personnel->full_name, 'response' => $a->response, 'is_organizer' => $a->is_organizer])
                ->values()->all();
        } elseif ($tracked) {
            $answers = $rows->keyBy('personnel_id');
            $ids = array_values(array_unique(array_merge([], ...array_values(AttendeeResolver::rolePersonnel($roleIds)))));
            $attendees = Personnel::query()->whereIn('id', $ids)->orderBy('full_name')->get(['id', 'full_name'])
                ->map(fn (Personnel $p) => ['personnel_id' => $p->id, 'name' => $p->full_name, 'response' => $answers->get($p->id)?->response,
                    'is_organizer' => (bool) $answers->get($p->id)?->is_organizer])->all();
        }
        $canEdit = $this->canWrite($user, $event);

        return response()->json([
            'id' => $event->id, 'title' => $event->title, 'description' => $event->description, 'location' => $event->location,
            'starts_at' => $this->format($event->starts_at, $event->all_day), 'ends_at' => $this->format($event->ends_at, $event->all_day),
            'all_day' => $event->all_day, 'color' => $event->color, 'category' => $event->category, 'visibility' => $event->visibility,
            'track_attendance' => $event->track_attendance, 'recurrence_rule' => $event->recurrence_rule,
            'recurrence_until' => $event->recurrence_until === null ? null : $this->format($event->recurrence_until, $event->all_day),
            'recurring' => $event->recurrence_rule !== null, 'source_locked' => $event->isLocked(),
            'creator' => $event->creator?->displayName(),
            'roles' => $event->visibility === 'role' ? $event->roles->map(fn (Role $r) => ['id' => $r->id, 'name' => $r->name])->values()->all() : [],
            'attendees' => $attendees,
            'my_response' => $mine?->response,
            'can_respond' => $user->personnel_id !== null && ($mine !== null || $isMember),
            'can_edit' => $canEdit, 'can_delete' => $canEdit,
            'conflicts' => $canEdit && $attendees !== null && $event->visibility !== 'role'
                ? ConflictDetector::find($event, $rows->where('is_organizer', false)->pluck('personnel_id')->all(), $user) : [],
        ]);
    }

    public function store(CalendarEventRequest $request): JsonResponse
    {
        $user = $request->user();
        $event = DB::transaction(function () use ($request, $user) {
            $event = (new CalendarEvent)->forceFill($request->event() + ['created_by' => $user->id]);
            $event->save();
            $event->roles()->sync($request->roleIds());
            $this->syncAttendees($event, $request->attendeeIds(), $user->personnel_id, false);

            return $event;
        });
        $this->notify($event, AttendeeResolver::notifyUserIds($event), []);
        $this->audit($user, 'Termin erstellt', $event);

        return response()->json(['id' => $event->id, 'conflicts' => ConflictDetector::find($event, $request->attendeeIds(), $user)], 201);
    }

    public function update(CalendarEventRequest $request, CalendarEvent $event): JsonResponse
    {
        // CalendarEventRequest::authorize: invisible => 404, mirrored absence => 403 (also for super admins), then the policy.
        $user = $request->user();
        $wasRole = $event->visibility === 'role';
        $before = AttendeeResolver::notifyUserIds($event);
        $event->forceFill($request->event());
        $relevant = $event->isDirty(self::NOTIFY_FIELDS);
        DB::transaction(function () use ($request, $event, $wasRole) {
            $event->save();
            $event->roles()->sync($request->roleIds());
            $this->syncAttendees($event, $request->attendeeIds(), $event->creator?->personnel_id, $wasRole && $event->visibility === 'role');
        });
        $after = AttendeeResolver::notifyUserIds($event);
        $this->notify($event, array_values(array_diff($after, $before)), $relevant ? array_values(array_intersect($after, $before)) : []);
        $this->audit($user, 'Termin bearbeitet', $event);

        return response()->json(['id' => $event->id, 'conflicts' => ConflictDetector::find($event, $request->attendeeIds(), $user)]);
    }

    public function destroy(Request $request, CalendarEvent $event): HttpResponse
    {
        abort_unless($request->user()->can('view', $event), 404);
        abort_if($event->isLocked(), 403);
        $this->authorize('delete', $event);
        $event->delete();
        $this->audit($request->user(), 'Termin gelöscht', $event);

        return response()->noContent();
    }

    public function respond(Request $request, CalendarEvent $event): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('view', $event), 404);
        abort_if($event->isLocked(), 403);
        $data = $request->validate(['response' => ['required', 'string', 'in:accepted,declined,tentative']]);
        abort_if($user->personnel_id === null, 403);

        $row = CalendarAttendee::query()->where('event_id', $event->id)->where('personnel_id', $user->personnel_id)->first();
        if ($row === null) {
            $member = $event->visibility === 'role' && $event->track_attendance
                && $event->roles()->whereIn('roles.id', $user->roles->pluck('id'))->exists();
            abort_unless($member, 403);
            $row = (new CalendarAttendee)->forceFill(['event_id' => $event->id, 'personnel_id' => $user->personnel_id, 'is_organizer' => false]);
        }
        try {
            $row->forceFill(['response' => $data['response'], 'responded_at' => now()])->save();
        } catch (UniqueConstraintViolationException) {
            $row = CalendarAttendee::query()->where('event_id', $event->id)->where('personnel_id', $user->personnel_id)->firstOrFail();
            $row->forceFill(['response' => $data['response'], 'responded_at' => now()])->save();
        }

        return response()->json(['response' => $row->response]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function window(Request $request): array
    {
        $pattern = '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/';
        $request->validate([
            'from' => ['required', 'string', 'regex:'.$pattern, 'date'],
            'to' => ['required', 'string', 'regex:'.$pattern, 'date'],
        ]);
        $parse = fn (string $v) => CarbonImmutable::parse($v, preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $v) === 1 ? null : (string) config('ignis.timezone'))->utc();
        $from = $parse($request->string('from')->toString());
        $to = $parse($request->string('to')->toString());
        if ($to <= $from || $from->diffInDays($to) > self::MAX_WINDOW_DAYS) {
            throw ValidationException::withMessages(['to' => trans('calendar.validation.window')]);
        }

        return [$from, $to];
    }

    private function format(CarbonImmutable $value, bool $allDay): string
    {
        return $allDay ? $value->toDateString() : $value->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function canWrite(User $user, CalendarEvent $event): bool
    {
        return ! $event->isLocked() && $user->can('update', $event);
    }

    /**
     * The creator's record is the organizer (accepted). Only attendees/private keep an explicit list; a role event
     * keeps the rows its members created by answering, as long as it stays a role event.
     *
     * @param  list<int>  $invited
     */
    private function syncAttendees(CalendarEvent $event, array $invited, ?int $organizer, bool $keepAnswers): void
    {
        $wanted = array_values(array_unique(array_merge($invited, $organizer === null ? [] : [$organizer])));
        if (! $keepAnswers) {
            $event->attendees()->whereNotIn('personnel_id', $wanted)->where('is_organizer', false)->delete();
        }
        $existing = $event->attendees()->pluck('personnel_id')->map(fn ($id) => (int) $id)->all();
        $now = now();
        foreach (array_diff($wanted, $existing) as $pid) {
            $isOrganizer = $pid === $organizer;
            (new CalendarAttendee)->forceFill([
                'event_id' => $event->id, 'personnel_id' => $pid, 'is_organizer' => $isOrganizer,
                'response' => $isOrganizer ? 'accepted' : 'pending', 'responded_at' => $isOrganizer ? $now : null,
            ])->save();
        }
    }

    /**
     * @param  list<int>  $created  accounts that get "Termin angelegt"
     * @param  list<int>  $updated  accounts that get "Termin geändert"
     */
    private function notify(CalendarEvent $event, array $created, array $updated): void
    {
        try {
            foreach (['created' => $created, 'updated' => $updated] as $kind => $ids) {
                if ($ids !== []) {
                    $this->send($event, $kind, $ids);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @param list<int> $ids */
    private function send(CalendarEvent $event, string $kind, array $ids): void
    {
        $zone = (string) config('ignis.timezone');
        $date = trans('calendar.format.date');
        $when = $event->all_day
            ? $event->starts_at->format($date).($event->ends_at->toDateString() !== $event->starts_at->toDateString() ? ' – '.$event->ends_at->format($date) : '')
            : $event->starts_at->setTimezone($zone)->format(trans('calendar.format.datetime'));
        Notification::send(User::query()->whereIn('id', $ids)->get(), new InboxNotification(
            'calendar',
            trans('calendar.notification.'.$kind, ['title' => $event->title]),
            $when.($event->location !== null ? ' · '.$event->location : ''),
            '/calendar?event='.$event->id,
        ));
    }

    private function audit(User $user, string $action, CalendarEvent $event): void
    {
        AuditLogger::log($user, $action, 'ID: '.$event->id.', Titel: '.$event->title, self::MODULE, true, ['id' => $event->id]);
    }
}
