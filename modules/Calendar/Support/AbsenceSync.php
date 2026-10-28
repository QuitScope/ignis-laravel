<?php

namespace Modules\Calendar\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Calendar\Models\CalendarAttendee;
use Modules\Calendar\Models\CalendarEvent;
use Modules\Forms\Models\FormSubmission;

/**
 * Mirrors an accepted absence application (type.is_absence, answers von_datum/bis_datum) into exactly one locked
 * all-day event (form_submission_id unique). Idempotent: every call brings the mirror to the state the submission
 * implies. Any other status, a type without is_absence or unusable dates remove the mirror.
 * Privacy: the event never carries a description, location or any answer; the title is the applicant name without a
 * trailing " (Dienstnummer)" group. Nothing is notified (the calendar's notifications live in the controller only).
 */
final class AbsenceSync
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const REMOVED = 'removed';

    public const SKIPPED_INVALID = 'skipped-invalid';

    public const UNCHANGED = 'unchanged';

    /** @return string one of the constants: what happened to the mirror */
    public static function sync(FormSubmission $s): string
    {
        $s->loadMissing('type');
        if (! $s->type->is_absence || $s->status !== 'accepted') {
            return self::remove($s->id) ? self::REMOVED : self::UNCHANGED;
        }
        $range = self::range($s->answers);
        if ($range === null) {
            Log::warning('Absence mirror skipped: invalid dates', ['submission_id' => $s->id]);
            self::remove($s->id);

            return self::SKIPPED_INVALID;
        }

        $values = [
            'title' => 'Abwesend: '.self::name($s->applicant_name),
            'description' => null, 'location' => null,
            'starts_at' => $range[0].' 00:00:00', 'ends_at' => $range[1].' 00:00:00',
            'all_day' => true, 'color' => 'gray', 'category' => 'absence', 'visibility' => 'all', 'track_attendance' => false,
            'created_by' => $s->decided_by, 'recurrence_rule' => null, 'recurrence_until' => null,
        ];

        try {
            return DB::transaction(function () use ($s, $values) {
                $event = CalendarEvent::query()->where('form_submission_id', $s->id)->first();
                $created = $event === null;
                $event ??= (new CalendarEvent)->forceFill(['form_submission_id' => $s->id]);
                $event->forceFill($values)->save();
                if ($s->personnel_id !== null) {
                    CalendarAttendee::query()->where('event_id', $event->id)->where('personnel_id', '!=', $s->personnel_id)->where('is_organizer', true)->delete();
                    $organizer = $event->attendees()->where('personnel_id', $s->personnel_id)->first() ?? new CalendarAttendee;
                    $organizer->forceFill(['event_id' => $event->id, 'personnel_id' => $s->personnel_id, 'is_organizer' => true, 'response' => 'accepted',
                        'responded_at' => $organizer->response === 'accepted' && $organizer->responded_at !== null ? $organizer->responded_at : now()])->save();
                } else {
                    CalendarAttendee::query()->where('event_id', $event->id)->where('is_organizer', true)->delete();
                }

                return $created ? self::CREATED : self::UPDATED;
            });
        } catch (UniqueConstraintViolationException) {
            return self::UPDATED;
        }
    }

    /**
     * Brings every mirror in line (calendar:backfill-absences, CalendarImporter): accepted submissions of absence types
     * are synced, mirrors whose submission is no longer an accepted absence are removed. Idempotent; $dry counts the same
     * way and writes nothing. No module check (callers decide).
     *
     * @return array{created: int, updated: int, removed: int, skipped-invalid: int}
     */
    public static function backfill(bool $dry = false): array
    {
        $n = [self::CREATED => 0, self::UPDATED => 0, self::REMOVED => 0, self::SKIPPED_INVALID => 0];

        FormSubmission::query()->with('type')->where('status', 'accepted')
            ->whereHas('type', fn ($q) => $q->where('is_absence', true))->orderBy('id')
            ->chunkById(200, function ($rows) use (&$n, $dry) {
                foreach ($rows as $s) {
                    $has = CalendarEvent::query()->where('form_submission_id', $s->id)->exists();
                    $key = $dry
                        ? (self::range($s->answers) === null ? self::SKIPPED_INVALID : ($has ? self::UPDATED : self::CREATED))
                        : self::sync($s);
                    if (isset($n[$key])) {
                        $n[$key]++;
                    }
                    if ($key === self::SKIPPED_INVALID && $has) {
                        $n[self::REMOVED]++;
                    }
                }
            });

        $stale = CalendarEvent::query()->whereNotNull('form_submission_id')->whereNotIn('form_submission_id', fn ($q) => $q
            ->select('form_submissions.id')->from('form_submissions')
            ->join('form_types', 'form_types.id', '=', 'form_submissions.form_type_id')
            ->where('form_submissions.status', 'accepted')->where('form_types.is_absence', true));
        $n[self::REMOVED] += $dry ? $stale->count() : DB::transaction(fn () => $stale->delete());

        return $n;
    }

    public static function remove(int $submissionId): bool
    {
        return CalendarEvent::query()->where('form_submission_id', $submissionId)->delete() > 0;
    }

    public static function name(string $applicant): string
    {
        $name = trim((string) preg_replace('/\s*\([^()]*\)\s*$/u', '', $applicant));

        return $name === '' ? trim($applicant) : $name;
    }

    /** @return ?array{0: string, 1: string} [von, bis] as Y-m-d, null when missing, unreadable or bis < von */
    public static function range(mixed $answers): ?array
    {
        $found = [];
        foreach (is_array($answers) ? $answers : [] as $a) {
            if (is_array($a) && in_array($a['key'] ?? null, ['von_datum', 'bis_datum'], true) && is_string($a['value'] ?? null)) {
                $found[$a['key']] = self::date($a['value']);
            }
        }
        $von = $found['von_datum'] ?? null;
        $bis = $found['bis_datum'] ?? null;

        return $von !== null && $bis !== null && $bis >= $von ? [$von, $bis] : null;
    }

    private static function date(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:(?:T|\s+)([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?)?$/D', $value, $m) === 1) {
            [, $y, $mo, $d] = $m;
        } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/D', $value, $m) === 1) {
            [, $d, $mo, $y] = $m;
        } else {
            return null;
        }

        return checkdate((int) $mo, (int) $d, (int) $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }
}
