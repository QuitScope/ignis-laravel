<?php

namespace App\Import;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Calendar\Http\Requests\CalendarEventRequest;
use Modules\Calendar\Models\CalendarEvent;
use Modules\Calendar\Support\AbsenceSync;
use Modules\Calendar\Support\IcalToken;
use Modules\Calendar\Support\RecurrenceExpander;
use Modules\Forms\Models\FormSubmission;
use Throwable;

/**
 * intra_calendar_events / _attendees / _event_roles => calendar_events / calendar_attendees / calendar_event_role
 * (module Calendar), entity calendar_event in legacy_id_map; intra_users.ical_token => users.calendar_token_hash
 * (only sha256 of the plaintext, entity calendar_token). Report rows calendar_event, calendar_attendee,
 * calendar_token.
 *
 * - Times: timed starts_at/ends_at are wall time of the legacy PHP zone => UTC (LegacyClock); all-day
 *   events keep only the date part of both (no conversion; 00:00:00, end inclusive). End before start => start (all-day)
 *   or start + 1 h (timed).
 * - title > 160 and description > 2000 characters are cut; color orange => accent, category dienst => duty; unknown
 *   color/category/visibility => accent/general/private (the safest). created_by via the user map, else null.
 * - Series (normalised to the subset EventRequest accepts, so the feed's invariants hold): FREQ DAILY|WEEKLY|MONTHLY,
 *   INTERVAL 1-999, BYDAY (WEEKLY only), COUNT 1-9999 or UNTIL. Like the legacy expander, COUNT in the rule beats an
 *   UNTIL in the rule, and an UNTIL in the rule beats the recurrence_until column; COUNT plus the column keeps whichever
 *   ends the series first. WEEKLY without BYDAY gets the start's weekday (legacy showed it daily); a
 *   start outside BYDAY moves to the first listed weekday on or after it (as the legacy view showed it).
 *   UNTIL: a date = end of that day in config('ignis.timezone') (all-day: the date), a time = that instant. Unparseable
 *   rule, invalid or past UNTIL, or an occurrence longer than CalendarEvent::MAX_SERIES_DAYS => single event. Rows with
 *   parent_event_id (exceptions, never created) => single event.
 * - source=antrag rows (absence mirrors) are not imported; at the end of the run AbsenceSync::backfill() recreates the
 *   mirrors from the imported applications (without the reason) and removes stale ones.
 * - Attendees via the personnel map (missing => dropped), unknown response => pending; roles via the role map.
 * - Tokens are taken over once per account, never over a subscription the account already has in the new instance.
 * Every adjustment is a counted warning. Re-runnable by legacy_id_map; only the stale absence mirrors are ever deleted;
 * a failing row is rolled back alone.
 */
final class CalendarImporter implements Importer
{
    private const ENTITY = 'calendar';

    private const CHUNK = 500;

    public const COLORS = ['orange' => 'accent', 'blue' => 'blue', 'green' => 'green', 'red' => 'red', 'purple' => 'purple', 'gray' => 'gray'];

    public const CATEGORIES = ['general' => 'general', 'meeting' => 'meeting', 'training' => 'training', 'dienst' => 'duty', 'absence' => 'absence'];

    private const RESPONSES = ['pending', 'accepted', 'declined', 'tentative'];

    private const RULE_KEYS = ['FREQ', 'INTERVAL', 'BYDAY', 'COUNT', 'UNTIL'];

    private ImportContext $ctx;

    /** @var array<string, list<int|string>> warning => legacy ids */
    private array $warn = [];

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return ['user', 'personnel', 'role', 'form'];
    }

    public function requiredTables(): array
    {
        return [];
    }

    public function import(ImportContext $ctx): void
    {
        $this->ctx = $ctx;
        $this->warn = [];
        $missing = null;
        foreach (['intra_calendar_events', 'intra_calendar_attendees', 'intra_calendar_event_roles'] as $table) {
            if (! $ctx->legacy()->tableExists($table)) {
                $missing = $table;
                break;
            }
        }
        if ($missing !== null) {
            $ctx->report()->note(self::ENTITY.": legacy table {$missing} missing (plugin calendar not installed), no events imported.");
            $this->absences();

            return;
        }

        DB::transaction(function () {
            $this->events();
            $this->absences();
            $this->tokens();
        });

        foreach ($this->warn as $what => $ids) {
            $ctx->report()->warnRows($what, array_values(array_unique($ids)));
        }
    }

    private function events(): void
    {
        $ids = $this->ctx->ids();
        $users = $ids->forEntity('user');
        $personnel = $ids->forEntity('personnel');
        $roles = $ids->forEntity('role');
        $submissions = $ids->forEntity('form_submission');

        $this->ctx->legacy()->tableWithInstants('intra_calendar_events', ['created_at', 'updated_at'])
            ->chunkById(self::CHUNK, function ($rows) use ($ids, $users, $personnel, $roles, $submissions) {
                $report = $this->ctx->report();
                $eventIds = $rows->pluck('id')->all();
                $attendees = $this->ctx->legacy()->tableWithInstants('intra_calendar_attendees', ['created_at'])
                    ->whereIn('event_id', $eventIds)->orderBy('id')->get()->groupBy('event_id');
                $eventRoles = $this->ctx->legacy()->table('intra_calendar_event_roles')->whereIn('event_id', $eventIds)
                    ->orderBy('id')->get()->groupBy('event_id');

                foreach ($rows as $row) {
                    $report->read('calendar_event');
                    $legacyId = (int) $row->id;
                    $legacyAttendees = $attendees->get($row->id, collect())->all();
                    $report->read('calendar_attendee', count($legacyAttendees));
                    if ((string) $row->source === 'antrag') {
                        $report->skippedCount('calendar_event', 1);
                        $report->skippedCount('calendar_attendee', count($legacyAttendees));
                        $this->warn[isset($submissions[(string) $row->source_ref_id])
                            ? 'calendar absence mirror(s) not imported (recreated from the applications at the end of this run)'
                            : 'calendar absence mirror(s) not imported, their application was not imported'][] = $legacyId;

                        continue;
                    }
                    $problems = [];
                    $values = $this->values($row, $users, $problems);
                    if ($values === null) {
                        $report->skipped('calendar_event', 'no usable start', ['legacy_id' => $legacyId]);
                        $report->skippedCount('calendar_attendee', count($legacyAttendees));

                        continue;
                    }
                    $rowAttendees = $this->attendees($legacyAttendees, $personnel, $problems);
                    $roleIds = [];
                    foreach ($eventRoles->get($row->id, collect()) as $pivot) {
                        $role = $roles[(string) $pivot->role_id] ?? null;
                        if ($role === null) {
                            $problems[] = 'role(s) not imported, dropped from the event';
                        } else {
                            $roleIds[] = $role;
                        }
                    }
                    try {
                        DB::transaction(function () use ($ids, $legacyId, $values, $rowAttendees, $roleIds) {
                            $id = $ids->write('calendar_event', $legacyId, 'calendar_events', $values);
                            if ($rowAttendees !== []) {
                                DB::table('calendar_attendees')->upsert(array_map(fn (array $a) => ['event_id' => $id] + $a, $rowAttendees),
                                    ['event_id', 'personnel_id'], ['response', 'responded_at', 'is_organizer', 'updated_at']);
                            }
                            DB::table('calendar_event_role')->insertOrIgnore(array_map(fn (int $r) => ['event_id' => $id, 'role_id' => $r], array_unique($roleIds)));
                        });
                    } catch (Throwable $e) {
                        $report->failed('calendar_event', "legacy calendar event {$legacyId}: ".ImportReport::describe($e));
                        $report->failedCount('calendar_attendee', count($legacyAttendees));

                        continue;
                    }
                    $report->written('calendar_event');
                    $report->written('calendar_attendee', count($rowAttendees));
                    $report->skippedCount('calendar_attendee', count($legacyAttendees) - count($rowAttendees));
                    foreach ($problems as $what) {
                        $this->warn["calendar event(s): {$what}"][] = $legacyId;
                    }
                }
            });
    }

    private function absences(): void
    {
        if (! class_exists(FormSubmission::class)) {
            $this->ctx->report()->note(self::ENTITY.': module forms missing, no absence mirrors created.');

            return;
        }
        try {
            $n = DB::transaction(fn () => AbsenceSync::backfill($this->ctx->dryRun()));
        } catch (Throwable $e) {
            $this->ctx->report()->read('calendar_absence');
            $this->ctx->report()->failed('calendar_absence', 'absence mirrors: '.ImportReport::describe($e));

            return;
        }
        $this->ctx->report()->note(sprintf('%s: Abwesenheitstermine aus Anträgen erzeugt/aktualisiert/entfernt: %d/%d/%d (ungültige Daten übersprungen: %d)%s',
            self::ENTITY, $n[AbsenceSync::CREATED], $n[AbsenceSync::UPDATED], $n[AbsenceSync::REMOVED], $n[AbsenceSync::SKIPPED_INVALID],
            $this->ctx->dryRun() ? ' (Probelauf)' : ''));
    }

    /**
     * Column values of one event, null when its start is unusable.
     *
     * @param  array<int|string, int>  $users
     * @param  list<string>  $problems
     * @return ?array<string, mixed>
     */
    private function values(object $row, array $users, array &$problems): ?array
    {
        $allDay = (bool) $row->all_day;
        $starts = $this->time($row->starts_at, $allDay);
        if ($starts === null) {
            return null;
        }
        $ends = $this->time($row->ends_at, $allDay);
        if ($ends === null || $ends < $starts) {
            $problems[] = 'end missing or before the start, set to the start (timed: start + 1 hour)';
            $ends = $allDay ? $starts : CarbonImmutable::parse($starts, 'UTC')->addHour()->format('Y-m-d H:i:s');
        }

        $title = trim((string) $row->title);
        if ($title === '') {
            $problems[] = 'empty title, set to "Termin"';
            $title = 'Termin';
        } elseif (mb_strlen($title) > 160) {
            $problems[] = 'title over 160 characters, cut';
            $title = mb_substr($title, 0, 160);
        }
        $description = trim((string) $row->description);
        if (mb_strlen($description) > 2000) {
            $problems[] = 'description over 2000 characters, cut';
            $description = mb_substr($description, 0, 2000);
        }
        $location = mb_substr(trim((string) $row->location), 0, 255);

        $color = self::COLORS[(string) $row->color] ?? null;
        if ($color === null) {
            $problems[] = "unknown color '".mb_substr((string) $row->color, 0, 20)."', set to accent";
        }
        $category = self::CATEGORIES[(string) $row->category] ?? null;
        if ($category === null) {
            $problems[] = "unknown category '".mb_substr((string) $row->category, 0, 32)."', set to general";
        }
        $visibility = (string) $row->visibility;
        if (! in_array($visibility, CalendarEvent::VISIBILITIES, true)) {
            $problems[] = "unknown visibility '".mb_substr($visibility, 0, 20)."', set to private";
            $visibility = 'private';
        }
        $createdBy = $row->created_by === null ? null : ($users[(string) $row->created_by] ?? null);
        if ($createdBy === null) {
            $problems[] = 'creating account not imported, left empty';
        }
        if ($row->parent_event_id !== null) {
            $problems[] = 'series exception (parent_event_id) imported as a single event';
            [$rule, $until] = [null, null];
        } else {
            [$rule, $until] = $this->series($row, $allDay, $starts, $ends, $problems);
        }
        $created = LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString();

        return [
            'title' => $title,
            'description' => $description === '' ? null : $description,
            'location' => $location === '' ? null : $location,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'all_day' => $allDay,
            'color' => $color ?? 'accent',
            'category' => $category ?? 'general',
            'visibility' => $visibility,
            'track_attendance' => $visibility === 'role' && (bool) $row->track_attendance,
            'form_submission_id' => null,
            'created_by' => $createdBy,
            'recurrence_rule' => $rule,
            'recurrence_until' => $until,
            'created_at' => $created,
            'updated_at' => LegacyClock::instant($row, 'updated_at') ?? $created,
        ];
    }

    private function time(mixed $value, bool $allDay): ?string
    {
        $value = trim((string) $value);
        if ($allDay) {
            return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? "{$m[1]}-{$m[2]}-{$m[3]} 00:00:00" : null;
        }

        return LegacyClock::toApp($value);
    }

    /**
     * The normalised rule and recurrence_until ('Y-m-d H:i:s' UTC; all-day: the date), or [null, null] for a single event.
     * A weekly series whose start is not one of its weekdays starts on the first of them instead ($starts/$ends move).
     *
     * @param  list<string>  $problems
     * @return array{0: ?string, 1: ?string}
     */
    private function series(object $row, bool $allDay, string &$starts, string &$ends, array &$problems): array
    {
        $raw = trim((string) $row->recurrence_rule);
        if ($raw === '') {
            return [null, null];
        }
        $single = function (string $why) use (&$problems): array {
            $problems[] = "series {$why}, imported as a single event";

            return [null, null];
        };
        $parts = [];
        foreach (explode(';', strtoupper($raw)) as $segment) {
            if (str_contains($segment, '=')) {
                [$key, $value] = explode('=', $segment, 2);
                $parts[trim($key)] = trim($value);
            }
        }
        $freq = $parts['FREQ'] ?? '';
        if (! in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY'], true)) {
            return $single('rule not understood');
        }
        $span = CarbonImmutable::parse($starts, 'UTC')->diffInSeconds(CarbonImmutable::parse($ends, 'UTC')->addDays($allDay ? 1 : 0));
        if ($span > CalendarEvent::MAX_SERIES_DAYS * 86400) {
            return $single('occurrence longer than '.CalendarEvent::MAX_SERIES_DAYS.' days');
        }
        if (array_diff(array_keys($parts), self::RULE_KEYS) !== [] || ($freq !== 'WEEKLY' && ($parts['BYDAY'] ?? '') !== '')) {
            $problems[] = 'series rule: unsupported parts dropped';
        }
        $interval = max(1, (int) ($parts['INTERVAL'] ?? 1));
        if ($interval > 999) {
            $problems[] = 'series INTERVAL over 999, set to 999';
            $interval = 999;
        }
        $byday = [];
        [$st, $en] = [$starts, $ends];
        if ($freq === 'WEEKLY') {
            foreach (array_filter(array_map('trim', explode(',', $parts['BYDAY'] ?? ''))) as $code) {
                if (isset(RecurrenceExpander::DAYS[$code])) {
                    $byday[] = RecurrenceExpander::DAYS[$code];
                } else {
                    $problems[] = 'series rule: unknown weekday dropped';
                }
            }
            $zone = (string) config('ignis.timezone');
            $weekday = ($allDay ? CarbonImmutable::parse($starts, 'UTC') : CarbonImmutable::parse($starts, 'UTC')->setTimezone($zone))->dayOfWeekIso;
            if ($byday === []) {
                $problems[] = 'Wochenserie ohne Wochentag: Wochentag des Beginns gesetzt (weekly series without BYDAY, legacy showed it daily)';
                $byday = [$weekday];
            } elseif (! in_array($weekday, $byday, true)) {
                // The legacy view showed the first occurrence on the next listed weekday; the series starts there
                // (wall time and duration kept), so start ∈ BYDAY holds and COUNT counts the same days.
                $problems[] = 'Serienbeginn auf ersten Wochentag der Serie verschoben (weekly series whose start is not one of its weekdays)';
                $shift = min(array_map(fn (int $d) => ($d - $weekday + 7) % 7, $byday));
                if ($allDay) {
                    [$st, $en] = array_map(fn (string $v) => CarbonImmutable::parse($v, 'UTC')->addDays($shift)->format('Y-m-d H:i:s'), [$starts, $ends]);
                } else {
                    $from = CarbonImmutable::parse($starts, 'UTC');
                    $moved = $from->setTimezone($zone)->addDays($shift)->utc();
                    $st = $moved->format('Y-m-d H:i:s');
                    $en = $moved->addSeconds((int) $from->diffInSeconds(CarbonImmutable::parse($ends, 'UTC')))->format('Y-m-d H:i:s');
                }
            }
            $byday = array_values(array_unique($byday));
            sort($byday);
            if ($interval > 1) {
                $problems[] = 'Intervall wird jetzt berücksichtigt (weekly series with INTERVAL > 1; legacy ignored it and showed every week)';
            }
        }
        // As the legacy parser: COUNT (non-empty) wins over an UNTIL in the rule.
        $count = ($parts['COUNT'] ?? '') !== '' && $parts['COUNT'] !== '0' ? max(1, (int) $parts['COUNT']) : null;
        if ($count !== null && $count > 9999) {
            $problems[] = 'series COUNT over 9999, set to 9999';
            $count = 9999;
        }
        $until = null;
        if ($count !== null && ($parts['UNTIL'] ?? '') !== '') {
            $problems[] = 'series with COUNT and UNTIL in the rule, UNTIL dropped (legacy used COUNT only)';
        } elseif (($parts['UNTIL'] ?? '') !== '') {
            $until = $this->ruleUntil($parts['UNTIL'], $allDay);
            if ($until === null) {
                return $single('UNTIL is no valid date');
            }
        } elseif ($row->recurrence_until !== null && trim((string) $row->recurrence_until) !== '') {
            $until = $this->columnUntil((string) $row->recurrence_until, $allDay);
            if ($until === null) {
                return $single('recurrence_until is no valid date');
            }
        }

        if ($until !== null && $until < $st) {
            return $single('ends before it starts (UNTIL before the start)');
        }

        $rule = ['freq' => $freq, 'interval' => $interval, 'byday' => $byday, 'count' => $count, 'until' => null];
        if ($count !== null && $until !== null) {
            // COUNT plus the column: the legacy expander stopped at whichever came first; the new subset keeps one.
            $problems[] = 'series with COUNT and recurrence_until, kept the one that ends it first';
            $zone = (string) config('ignis.timezone');
            [$from, $to] = $allDay
                ? [CarbonImmutable::parse(substr($st, 0, 10), $zone), CarbonImmutable::parse(substr($until, 0, 10), $zone)->addDay()]
                : [CarbonImmutable::parse($st, 'UTC'), CarbonImmutable::parse($until, 'UTC')->addSecond()];
            $shown = count(RecurrenceExpander::expand(['starts_at' => $st, 'ends_at' => $en, 'all_day' => $allDay,
                'recurrence_rule' => CalendarEventRequest::normalise($rule), 'recurrence_until' => null], $from, $to, $count));
            if ($shown >= $count) {
                $until = null;
            } else {
                $rule['count'] = null;
            }
        }

        if ($rule['count'] === null && $until === null) {
            // Only reported: the legacy expander stopped 366 occurrences after the series start; the import keeps it running.
            $problems[] = 'series without an end (no COUNT, no UNTIL): legacy stopped showing it 366 occurrences after its start, now it continues';
        }
        [$starts, $ends] = [$st, $en];

        return [CalendarEventRequest::normalise($rule), $until];
    }

    private function ruleUntil(string $value, bool $allDay): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?(Z?)$/', $value, $m) === 1) {
            $value = "{$m[1]}{$m[2]}{$m[3]}T{$m[4]}{$m[5]}".($m[6] !== '' ? $m[6] : '00').$m[7];
        }
        $instant = RecurrenceExpander::ruleUntil($value, $allDay ? 'UTC' : (string) config('ignis.timezone'));

        return $instant === null ? null : ($allDay ? $instant->toDateString().' 00:00:00' : $instant->format('Y-m-d H:i:s'));
    }

    /**
     * The recurrence_until column (legacy PHP wall time). The legacy form stored a date as 00:00:00: that means the whole
     * day (end of it in the organisation's zone, as CalendarEventRequest); all-day series keep the date.
     */
    private function columnUntil(string $value, bool $allDay): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]00:00(?::00)?)?$/', $value, $m) === 1 || ($allDay && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1)) {
            return $this->ruleUntil("{$m[1]}-{$m[2]}-{$m[3]}", $allDay);
        }

        return $allDay ? null : LegacyClock::toApp($value);
    }

    /**
     * Attendee rows (without event_id) for the personnel files that were imported.
     *
     * @param  list<object>  $rows
     * @param  array<int|string, int>  $personnel
     * @param  list<string>  $problems
     * @return list<array<string, mixed>>
     */
    private function attendees(array $rows, array $personnel, array &$problems): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = $personnel[(string) $row->mitarbeiter_id] ?? null;
            if ($id === null) {
                $problems[] = 'attendee(s) without an imported personnel file, dropped';

                continue;
            }
            $response = (string) $row->response;
            if (! in_array($response, self::RESPONSES, true)) {
                $problems[] = "attendee(s) with an unknown response '".mb_substr($response, 0, 20)."', set to pending";
                $response = 'pending';
            }
            $created = LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString();
            $out[$id] = [
                'personnel_id' => $id,
                'response' => $response,
                'responded_at' => LegacyClock::toApp($row->responded_at),
                'is_organizer' => (bool) $row->is_organizer,
                'created_at' => $created,
                'updated_at' => $created,
            ];
        }

        return array_values($out);
    }

    /**
     * intra_users.ical_token => users.calendar_token_hash = sha256(token), so existing subscriptions keep working. Taken
     * over once per account (entity calendar_token): a later run never revives a token the account replaced or revoked.
     * The token itself never reaches the report or a log; only legacy user ids are named.
     */
    private function tokens(): void
    {
        if (! in_array('ical_token', $this->legacyColumns('intra_users'), true)) {
            return;
        }
        $ids = $this->ctx->ids();
        $report = $this->ctx->report();
        $users = $ids->forEntity('user');
        $rows = $this->ctx->legacy()->table('intra_users')->whereNotNull('ical_token')->where('ical_token', '!=', '')
            ->orderBy('id')->get(['id', 'ical_token']);
        foreach ($rows as $row) {
            $report->read('calendar_token');
            $legacyId = (int) $row->id;
            $user = $users[(string) $legacyId] ?? null;
            if ($user === null) {
                $report->skippedCount('calendar_token', 1);
                $this->warn['calendar subscription token(s) of accounts that were not imported, dropped'][] = $legacyId;

                continue;
            }
            if ($ids->has('calendar_token', $legacyId)) {
                $report->skippedCount('calendar_token', 1);

                continue;
            }
            $token = (string) $row->ical_token;
            if (preg_match('/^'.IcalToken::PATTERN.'$/D', $token) !== 1) {
                $report->skippedCount('calendar_token', 1);
                $this->warn['calendar subscription token(s) not 20-64 lower-case hex characters, dropped (the subscription has to be renewed)'][] = $legacyId;

                continue;
            }
            $hash = hash('sha256', $token);
            try {
                $taken = DB::table('users')->where('calendar_token_hash', $hash)->where('id', '!=', $user)->exists();
                if ($taken) {
                    $report->skippedCount('calendar_token', 1);
                    $this->warn['calendar subscription token(s) already held by another account, dropped (the first one wins)'][] = $legacyId;

                    continue;
                }
                $own = DB::table('users')->where('id', $user)->value('calendar_token_hash');
                if ($own !== null && $own !== $hash) {
                    $report->skippedCount('calendar_token', 1);
                    $this->warn['calendar subscription token(s) not taken over: the account already has a subscription in the new instance'][] = $legacyId;

                    continue;
                }
                DB::transaction(function () use ($ids, $legacyId, $user, $hash) {
                    DB::table('users')->where('id', $user)->update(['calendar_token_hash' => $hash]);
                    $ids->put('calendar_token', $legacyId, $user);
                });
            } catch (Throwable $e) {
                $report->failed('calendar_token', "legacy user {$legacyId}: ".ImportReport::describe($e));

                continue;
            }
            $report->written('calendar_token');
        }
    }

    /** @return list<string> */
    private function legacyColumns(string $table): array
    {
        return array_map(fn ($c) => (string) $c->Field, $this->ctx->legacy()->select("SHOW COLUMNS FROM `{$table}`"));
    }
}
