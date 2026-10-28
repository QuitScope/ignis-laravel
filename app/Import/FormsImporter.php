<?php

namespace App\Import;

use Illuminate\Support\Facades\DB;
use Modules\Forms\Models\FormField;
use Modules\Forms\Support\AnswerValidator;
use Modules\Forms\Support\DefaultTypes;
use Throwable;

/**
 * intra_antrag_typen / _felder / intra_antraege / _daten => form_types / form_fields / form_submissions (module Forms),
 * entities form_type, form_field, form_submission in legacy_id_map (plus form_uniqueid: legacy uniqueid => submission,
 * for LegacyLinkRewriter). Report rows form_type, form_field, form_submission.
 *
 * - Types: a legacy type without a map entry adopts an unmapped type of the new instance with the same name (ignoring
 *   case and blanks) only when that name is one of the two the Forms migration seeds (DefaultTypes::NAMES) and the type
 *   has no submissions, so the seed is not duplicated; its fields are adopted by key. Any other same-name type stays
 *   untouched and the legacy type is created next to it (counted). Icon: Font Awesome class => Lucide name the forms UI draws (ICONS, ALIASES), else none. is_absence only for the
 *   names in ABSENCE_NAMES whose type has date fields von_datum and bis_datum (else false, counted).
 * - Fields: key normalised to ^[a-z][a-z0-9_]*$ (lower case, umlauts and ß transliterated, other characters => _, `f_` in front when it does not
 *   start with a letter), duplicates per type get _2, _3 (by legacy id); unknown feldtyp => text; optionen (one per
 *   line) => list, select only; unknown auto_fill => none. All counted.
 * - Submissions: uniqueid => public_id when it is six digits and free, else a new random one (counted, the legacy
 *   uniqueid is named in the warning); a mapped row keeps its public_id. Personnel via the personnel map, else the one personnel
 *   file with that Discord id, else none (counted). cirs_status (legacy Form::STATUS_*) 0 In Bearbeitung
 *   => new (the state of every fresh submission), but in_progress once an editor touched it (cirs_manager or
 *   cirs_time set); 1 Abgelehnt => rejected, 2 Aufgeschoben => deferred, 3 Angenommen => accepted, other =>
 *   in_progress (counted). time_added: legacy database clock (LegacyDbClock); cirs_time: PHP clock (LegacyClock) =>
 *   decided_at (so `new` never has one). cirs_manager => decided_by_name (decided_by stays empty: a name
 *   is no reliable account link).
 * - answers: snapshot [{key,label,type,width,value}] from intra_antraege_daten and the legacy field definitions, in
 *   field order; values typed like a new submission (checkbox bool, text trimmed and capped as AnswerValidator).
 *   Rows of deleted fields (orphans) are appended (label = feldname, type text, width full); for a feldname with
 *   several rows the last one wins. Both counted.
 * Re-runnable by legacy_id_map, never deletes; a failing row is rolled back alone.
 */
final class FormsImporter implements Importer
{
    private const ENTITY = 'form';

    private const CHUNK = 200;

    public const STATUSES = [0 => 'new', 1 => 'rejected', 2 => 'deferred', 3 => 'accepted'];

    public const ABSENCE_NAMES = ['urlaubsantrag', 'urlaub', 'freistellungsantrag', 'freistellung'];

    public const ICONS = ['award', 'calendar', 'chevrons-up', 'clipboard-list', 'file-text', 'graduation-cap', 'plane', 'stethoscope', 'umbrella', 'user'];

    public const ALIASES = [
        'angles-up' => 'chevrons-up', 'angle-double-up' => 'chevrons-up', 'umbrella-beach' => 'umbrella', 'file-alt' => 'file-text',
        'file-lines' => 'file-text', 'calendar-days' => 'calendar', 'calendar-alt' => 'calendar', 'user-graduate' => 'graduation-cap',
        'plane-departure' => 'plane', 'user-doctor' => 'stethoscope', 'user-md' => 'stethoscope',
    ];

    private const FA_PREFIXES = ['fa', 'fas', 'far', 'fab', 'fal', 'fad', 'fa-solid', 'fa-regular', 'fa-brands', 'fa-light', 'fa-duotone', 'fa-thin'];

    private const CAPS = ['textarea' => 10000, 'email' => 255, 'tel' => 50, 'number' => 50];

    private ImportContext $ctx;

    /** @var array<string, list<int|string>> warning => legacy ids */
    private array $warn = [];

    /** @var array<int, list<array{feldname: string, key: string, label: string, type: string, width: string}>> legacy type id => fields in display order */
    private array $fields = [];

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return ['user', 'personnel'];
    }

    public function requiredTables(): array
    {
        return [];
    }

    public function import(ImportContext $ctx): void
    {
        foreach (['intra_antrag_typen', 'intra_antrag_felder', 'intra_antraege', 'intra_antraege_daten'] as $table) {
            if (! $ctx->legacy()->tableExists($table)) {
                $ctx->report()->note(self::ENTITY.": legacy table {$table} missing (plugin forms not installed), skipped.");

                return;
            }
        }
        $this->ctx = $ctx;
        $this->warn = $this->fields = [];
        $dbClock = LegacyDbClock::for($ctx->legacy(), $ctx->report());
        $ctx->report()->note(self::ENTITY.': '.$dbClock->describe());

        DB::transaction(function () use ($dbClock) {
            $this->types();
            $this->submissions($dbClock);
        });

        foreach ($this->warn as $what => $ids) {
            $ctx->report()->warnRows($what, array_values(array_unique($ids)));
        }
    }

    private function types(): void
    {
        $ids = $this->ctx->ids();
        $report = $this->ctx->report();
        $users = $ids->forEntity('user');
        $legacyFields = $this->ctx->legacy()->table('intra_antrag_felder')->orderBy('id')->get()->groupBy('antragstyp_id');

        foreach ($this->ctx->legacy()->tableWithInstants('intra_antrag_typen', ['erstellt_am'])->orderBy('id')->get() as $row) {
            $report->read('form_type');
            $legacyId = (int) $row->id;
            $name = trim((string) $row->name);
            $name = mb_substr($name === '' ? "Antragstyp {$legacyId}" : $name, 0, 255);
            $created = LegacyClock::instant($row, 'erstellt_am') ?? now()->toDateTimeString();
            $createdBy = $row->erstellt_von === null ? null : ($users[(string) $row->erstellt_von] ?? null);
            if ($row->erstellt_von !== null && $createdBy === null) {
                $this->warn['form type(s): creating account not imported, left empty'][] = $legacyId;
            }
            $values = [
                'name' => $name,
                'description' => $row->beschreibung,
                'icon' => $this->icon($row->icon, $legacyId),
                'is_active' => (bool) $row->aktiv,
                'is_absence' => false,
                'sort_order' => max(0, (int) $row->sortierung),
                'created_by' => $createdBy,
                'created_at' => $created,
                'updated_at' => $created,
            ];
            $fields = $this->defineFields($legacyId, $legacyFields->get($row->id, collect())->all(), $created);
            try {
                $typeId = DB::transaction(function () use ($ids, $legacyId, $name, $values) {
                    if (! $ids->has('form_type', $legacyId) && ($seeded = $this->seededType($name, $legacyId)) !== null) {
                        $ids->put('form_type', $legacyId, $seeded);
                        $this->ctx->report()->note(self::ENTITY.": legacy type {$legacyId} '{$name}' merged into the existing type {$seeded} of the same name");
                    }

                    return $ids->write('form_type', $legacyId, 'form_types', $values);
                });
            } catch (Throwable $e) {
                $report->failed('form_type', "legacy form type {$legacyId}: ".ImportReport::describe($e));

                continue;
            }
            $report->written('form_type');

            foreach ($fields as [$field, $values]) {
                $report->read('form_field');
                try {
                    DB::transaction(function () use ($ids, $typeId, $field, $values) {
                        if (! $ids->has('form_field', $field)) {
                            $existing = DB::table('form_fields')->where('form_type_id', $typeId)->where('key', $values['key'])
                                ->whereNotIn('id', DB::table('legacy_id_map')->where('entity', 'form_field')->select('new_id'))->value('id');
                            if ($existing !== null) {
                                $ids->put('form_field', $field, (int) $existing);
                            }
                        }
                        $ids->write('form_field', $field, 'form_fields', $values + ['form_type_id' => $typeId]);
                    });
                    $report->written('form_field');
                } catch (Throwable $e) {
                    $report->failed('form_field', "legacy form field {$field}: ".ImportReport::describe($e));
                }
            }

            $absence = in_array(mb_strtolower($name), self::ABSENCE_NAMES, true);
            $dates = DB::table('form_fields')->where('form_type_id', $typeId)->whereIn('key', ['von_datum', 'bis_datum'])->where('type', 'date')->count() === 2;
            if ($absence && ! $dates) {
                $this->warn['form type(s) named like an absence type without date fields von_datum and bis_datum, not marked as absence'][] = $legacyId;
            }
            DB::table('form_types')->where('id', $typeId)->update(['is_absence' => $absence && $dates]);
        }
    }

    /**
     * Field rows of one legacy type, remembered for the snapshots (display order sortierung, id).
     *
     * @param  list<object>  $rows  ordered by id
     * @return list<array{0: int, 1: array<string, mixed>}> legacy field id => values
     */
    private function defineFields(int $type, array $rows, string $created): array
    {
        $out = $taken = $snapshot = $order = [];
        foreach ($rows as $row) {
            $legacyId = (int) $row->id;
            $feldname = (string) $row->feldname;
            $key = $this->uniqueKey($feldname, $taken, $legacyId, 'form field(s)');
            $fieldType = (string) $row->feldtyp;
            if (! in_array($fieldType, FormField::TYPES, true)) {
                $this->warn["form field(s) with an unknown type '".mb_substr($fieldType, 0, 30)."', imported as text"][] = $legacyId;
                $fieldType = 'text';
            }
            $autoFill = trim((string) $row->auto_fill);
            if ($autoFill !== '' && ! in_array($autoFill, FormField::AUTO_FILL, true)) {
                $this->warn["form field(s) with an unknown auto_fill '".mb_substr($autoFill, 0, 30)."', imported without"][] = $legacyId;
            }
            $options = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $row->optionen) ?: []), fn ($o) => $o !== '')));
            $label = trim((string) $row->label);
            $values = [
                'key' => $key,
                'label' => mb_substr($label === '' ? $feldname : $label, 0, 255),
                'type' => $fieldType,
                'options' => $fieldType === 'select' && $options !== [] ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
                'required' => (bool) $row->pflichtfeld,
                'placeholder' => $row->platzhalter === null ? null : mb_substr((string) $row->platzhalter, 0, 255),
                'hint' => $row->hinweistext,
                'width' => $row->breite === 'half' ? 'half' : 'full',
                'default_value' => $row->standardwert,
                'readonly' => (bool) $row->readonly,
                'auto_fill' => in_array($autoFill, FormField::AUTO_FILL, true) ? $autoFill : null,
                'sort_order' => 0,
                'created_at' => $created,
                'updated_at' => $created,
            ];
            $order[$legacyId] = [(int) $row->sortierung, $legacyId];
            $out[$legacyId] = $values;
            $snapshot[$legacyId] = ['feldname' => $feldname, 'key' => $key, 'label' => $values['label'], 'type' => $fieldType, 'width' => $values['width']];
        }
        asort($order);
        $this->fields[$type] = [];
        $fields = [];
        foreach (array_keys($order) as $i => $legacyId) {
            $this->fields[$type][] = $snapshot[$legacyId];
            $fields[] = [$legacyId, ['sort_order' => $i + 1] + $out[$legacyId]];
        }

        return $fields;
    }

    /**
     * $name as a field key (^[a-z][a-z0-9_]*$, at most 100 characters), not in $taken (then _2, _3 ...), added to $taken.
     *
     * @param  array<string, true>  $taken
     */
    private function uniqueKey(string $name, array &$taken, int|string $legacyId, string $what): string
    {
        $key = strtr(mb_strtolower(trim($name)), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $key = (string) preg_replace('/[^a-z0-9_]/u', '_', $key);
        if (preg_match('/^[a-z]/', $key) !== 1) {
            $key = 'f_'.$key;
        }
        $key = substr($key, 0, 100);
        if ($key !== $name) {
            $this->warn["{$what}: field name is no identifier (a-z, 0-9, _ starting with a letter), normalised"][] = $legacyId;
        }
        if (isset($taken[$key])) {
            for ($n = 2; isset($taken[substr($key, 0, 95)."_{$n}"]); $n++);
            $key = substr($key, 0, 95)."_{$n}";
            $this->warn["{$what}: field name used twice in one type, suffixed _2, _3 ..."][] = $legacyId;
        }
        $taken[$key] = true;

        return $key;
    }

    private function seededType(string $name, int $legacyId): ?int
    {
        $wanted = mb_strtolower(trim($name));
        $mapped = DB::table('legacy_id_map')->where('entity', 'form_type')->pluck('new_id')->map(fn ($id) => (int) $id)->all();
        $seedNames = array_map('mb_strtolower', DefaultTypes::NAMES);
        foreach (DB::table('form_types')->whereNotIn('id', $mapped)->orderBy('id')->get(['id', 'name']) as $type) {
            if (mb_strtolower(trim((string) $type->name)) !== $wanted) {
                continue;
            }
            if (in_array($wanted, $seedNames, true) && ! DB::table('form_submissions')->where('form_type_id', $type->id)->exists()) {
                return (int) $type->id;
            }
            $this->warn['form type(s) named like a type of the new instance that is no unused seed type, imported as a separate type'][] = $legacyId;
        }

        return null;
    }

    private function icon(mixed $raw, int $legacyId): ?string
    {
        $raw = strtolower(trim((string) $raw));
        foreach (array_reverse(preg_split('/\s+/', $raw) ?: []) as $token) {
            if (in_array($token, self::FA_PREFIXES, true)) {
                continue;
            }
            $name = (string) preg_replace('/^fa-/', '', $token);
            $name = self::ALIASES[$name] ?? $name;
            if (in_array($name, self::ICONS, true)) {
                return $name;
            }
            break;
        }
        if ($raw !== '') {
            $this->warn['form type(s): icon has no counterpart among the form icons, imported without icon (shown as a file)'][] = $legacyId;
        }

        return null;
    }

    private function submissions(LegacyDbClock $dbClock): void
    {
        $ids = $this->ctx->ids();
        $report = $this->ctx->report();
        $types = $ids->forEntity('form_type');
        $personnel = $ids->forEntity('personnel');
        // Discord id => personnel file, only where exactly one file has it (the rule of the legacy core migration).
        $discord = DB::table('personnel')->whereNotNull('discord_id')->where('discord_id', '!=', '')
            ->groupBy('discord_id')->havingRaw('COUNT(*) = 1')->selectRaw('discord_id, MIN(id) AS id')->pluck('id', 'discord_id')->all();

        $this->ctx->legacy()->table('intra_antraege')->chunkById(self::CHUNK, function ($rows) use ($ids, $report, $types, $personnel, $discord, $dbClock) {
            $data = $this->ctx->legacy()->table('intra_antraege_daten')->whereIn('antrag_id', $rows->pluck('id')->all())
                ->orderBy('id')->get()->groupBy('antrag_id');
            foreach ($rows as $row) {
                $report->read('form_submission');
                $legacyId = (int) $row->id;
                $typeId = $types[(string) $row->antragstyp_id] ?? null;
                if ($typeId === null) {
                    $report->skipped('form_submission', 'type not imported', ['legacy_id' => $legacyId, 'type' => $row->antragstyp_id]);

                    continue;
                }
                $discordId = trim((string) $row->discordid);
                $personnelId = $row->mitarbeiter_id === null ? null : ($personnel[(string) $row->mitarbeiter_id] ?? null);
                if ($personnelId === null) {
                    $personnelId = $discord[$discordId] ?? null;
                    $this->warn[$personnelId === null
                        ? 'form submission(s) without an imported personnel file and without a unique Discord match, kept without owner (only editors see them)'
                        : 'form submission(s) without an imported personnel file, matched to the one personnel file with their Discord id'][] = $legacyId;
                }
                $manager = trim((string) $row->cirs_manager);
                $touched = $manager !== '' || trim((string) $row->cirs_time) !== '';
                $status = self::STATUSES[(int) $row->cirs_status] ?? null;
                if ($status === 'new' && $touched) {
                    $status = 'in_progress';
                } elseif ($status === null) {
                    $this->warn["form submission(s) with an unknown status '".(int) $row->cirs_status."', set to in_progress"][] = $legacyId;
                    $status = 'in_progress';
                }
                $submitted = $dbClock->toApp($row->time_added);
                if ($submitted === null) {
                    $this->warn['form submission(s) without a usable submission time, set to the import time'][] = $legacyId;
                    $submitted = now()->toDateTimeString();
                }
                $decided = LegacyClock::toApp($row->cirs_time);
                $note = trim((string) $row->cirs_text);
                $rank = trim((string) $row->dienstgrad);
                $values = [
                    'form_type_id' => $typeId,
                    'personnel_id' => $personnelId,
                    'applicant_name' => mb_substr((string) $row->name_dn, 0, 255),
                    'applicant_rank' => $rank === '' ? null : mb_substr($rank, 0, 255),
                    'legacy_discord_id' => $discordId === '' ? null : mb_substr($discordId, 0, 64),
                    'status' => $status,
                    'decided_by' => null,
                    'decided_by_name' => $manager === '' ? null : mb_substr($manager, 0, 255),
                    'decision_note' => $note === '' ? null : (string) $row->cirs_text,
                    'submitted_at' => $submitted,
                    'decided_at' => $decided,
                    'answers' => json_encode($this->answers((int) $row->antragstyp_id, $legacyId, $data->get($row->id, collect())->all()), JSON_UNESCAPED_UNICODE),
                    'created_at' => $submitted,
                    'updated_at' => $decided ?? $submitted,
                ];
                $regenerated = null;
                try {
                    DB::transaction(function () use ($ids, $legacyId, $row, $values, &$regenerated) {
                        [$values['public_id'], $regenerated] = $this->publicId($legacyId, (string) $row->uniqueid);
                        $id = $ids->write('form_submission', $legacyId, 'form_submissions', $values);
                        $uniqueid = trim((string) $row->uniqueid);
                        if ($uniqueid !== '') {
                            $ids->put('form_uniqueid', $uniqueid, $id);
                        }
                    });
                } catch (Throwable $e) {
                    $report->failed('form_submission', "legacy form submission {$legacyId}: ".ImportReport::describe($e));

                    continue;
                }
                $report->written('form_submission');
                if ($regenerated !== null) {
                    $this->warn["form submission(s) whose legacy uniqueid was {$regenerated[0]}, given a new public id (old links are rewritten; legacy id 'uniqueid' => new)"][] = $regenerated[1];
                }
            }
        });
    }

    /**
     * The public id: the mapped row's, else the legacy uniqueid when six digits and free, else a new free one; the second
     * value is null or [reason, report entry] for a new one (reported by the caller after a successful write).
     *
     * @return array{0: string, 1: ?array{0: string, 1: string}}
     */
    private function publicId(int $legacyId, string $uniqueid): array
    {
        $current = $this->ctx->ids()->get('form_submission', $legacyId);
        $kept = $current === null ? null : DB::table('form_submissions')->where('id', $current)->value('public_id');
        if ($kept !== null) {
            return [(string) $kept, null];
        }
        $uniqueid = trim($uniqueid);
        $free = fn (string $id) => ! DB::table('form_submissions')->where('public_id', $id)->exists();
        $valid = preg_match('/^[0-9]{6}$/D', $uniqueid) === 1;
        if ($valid && $free($uniqueid)) {
            return [$uniqueid, null];
        }
        do {
            $new = (string) random_int(100000, 999999);
        } while (! $free($new));

        return [$new, [$valid ? 'taken in the new instance' : 'not six digits', "{$legacyId} '".mb_substr($uniqueid, 0, 20)."' => {$new}"]];
    }

    /**
     * The snapshot of one submission.
     *
     * @param  list<object>  $rows  intra_antraege_daten ordered by id
     * @return list<array{key: string, label: string, type: string, width: string, value: string|bool}>
     */
    private function answers(int $type, int $legacyId, array $rows): array
    {
        $values = [];
        $seen = [];
        foreach ($rows as $row) {
            $name = (string) $row->feldname;
            if (isset($seen[$name])) {
                $this->warn['form submission(s) with several values for one field, the last one kept'][] = $legacyId;
            }
            $seen[$name] = true;
            $values[$name] = (string) $row->wert;
        }
        $out = [];
        $taken = [];
        $fields = $this->fields[$type] ?? [];
        foreach ($fields as $field) {
            $taken[$field['key']] = true;
            $out[] = ['key' => $field['key'], 'label' => $field['label'], 'type' => $field['type'], 'width' => $field['width'],
                'value' => $this->value($field['type'], $values[$field['feldname']] ?? '', $legacyId)];
        }
        $known = array_flip(array_column($fields, 'feldname'));
        foreach ($values as $name => $value) {
            if (isset($known[$name])) {
                continue;
            }
            $this->warn['form submission(s) with values of deleted fields, kept with the field name as label'][] = $legacyId;
            $out[] = ['key' => $this->uniqueKey((string) $name, $taken, $legacyId, 'form submission value(s)'), 'label' => mb_substr((string) $name, 0, 255),
                'type' => 'text', 'width' => 'full', 'value' => $this->value('orphan', $value, $legacyId)];
        }

        return $out;
    }

    private function value(string $type, string $raw, int $legacyId): string|bool
    {
        $value = trim($raw);
        if ($type === 'checkbox') {
            if ($value !== '' && ! AnswerValidator::checked($value) && ! in_array(strtolower($value), ['0', 'off', 'false'], true)) {
                $this->warn['form submission(s) with a checkbox value other than 1/on/true/0/off/false, read as ticked (as legacy showed it)'][] = $legacyId;

                return true;
            }

            return AnswerValidator::checked($value);
        }
        $cap = $type === 'orphan' ? self::CAPS['textarea'] : (self::CAPS[$type] ?? 1000);
        if (mb_strlen($value) > $cap) {
            $this->warn["form submission(s) with a value over the {$cap} characters of its field type, cut"][] = $legacyId;
            $value = mb_substr($value, 0, $cap);
        }

        return $value;
    }
}
