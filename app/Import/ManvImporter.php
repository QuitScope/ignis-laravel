<?php

namespace App\Import;

use App\Http\LegacyUrlMap;
use App\Models\Poi;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * intra_manv_lagen / _ressourcen / _patienten / _log => manv_incidents / manv_resources / manv_patients / manv_log (module
 * ManvBoard), entities manv_incident, manv_resource, manv_patient, manv_log in legacy_id_map.
 *
 * - Clocks: erstellt_am, geaendert_am, log timestamp and transport_abfahrt are legacy database clock
 *   (LegacyDbClock); einsatzbeginn and sichtungskategorie_zeit are PHP clock (LegacyClock).
 * - Incidents: lna_/orgl_mitarbeiter_id hold ACCOUNT ids => user map => users.personnel_id; without a personnel file only
 *   the legacy name is kept (warning). The legacy name text stays as it was (it came from the browser); an empty one gets
 *   the file's name. Unknown status => active, einsatzende stays empty (never written by legacy).
 * - Account columns (erstellt_von, geaendert_von, sichtungskategorie_geaendert_von, benutzer_id) via the user map, else
 *   empty (counted warning per column). benutzer_name empty => the account's name, else empty (shown as "System").
 * - Resources: label unique per incident (duplicates => " (2)", " (3)" by id); vehicle_id only for exactly one active
 *   vehicle of that name (ambiguous => none, warning).
 * - Patients: numbers unique per incident (duplicates => -2, -3 by id); `tot` => SK5, empty enum values => untriaged /
 *   unknown sex; resource via transportmittel_rufname = label of the same incident (missing => text snapshot only);
 *   destination text kept, destination_poi_id only for exactly one hospital of that name; massnahmen and
 *   transport_ankunft are appended to the notes (never lost).
 * - Log: referenz_id rewritten to the new patient/resource (missing => empty, the text stays).
 * Every adjustment is a counted warning. Re-runnable by legacy_id_map, never deletes; a failing row is rolled back alone.
 */
final class ManvImporter implements Importer
{
    private const ENTITY = 'manv';

    private const CHUNK = 500;

    private const TABLES = ['intra_manv_lagen', 'intra_manv_ressourcen', 'intra_manv_patienten', 'intra_manv_log'];

    public const RESOURCE_TYPES = ['fahrzeug' => 'vehicle', 'personal' => 'personnel', 'material' => 'material'];

    public const RESOURCE_STATUSES = ['verfuegbar' => 'available', 'im_einsatz' => 'deployed', 'nicht_verfuegbar' => 'unavailable'];

    public const SEXES = ['m' => 'm', 'w' => 'f', 'd' => 'd', 'unbekannt' => 'unknown'];

    private const TRIAGE = ['SK1', 'SK2', 'SK3', 'SK4', 'SK5', 'SK6'];

    private ImportContext $ctx;

    private LegacyDbClock $db;

    /** @var array<string, list<int|string>> warning => legacy ids */
    private array $warn = [];

    /** @var array<int|string, int> legacy account id => new account id */
    private array $users = [];

    /** @var array<int, object{personnel_id: ?int, name: ?string}> new account id => personnel file (when it exists) and display name */
    private array $accounts = [];

    /** @var array<int, string> legacy incident id => created_at (fallback for log rows without a time) */
    private array $created = [];

    /** @var array<int, array<string, int>> legacy incident id => normalised label => legacy resource id (first by id) */
    private array $labels = [];

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return ['user', 'personnel', 'account_link', 'vehicle', 'poi'];
    }

    public function requiredTables(): array
    {
        return [];
    }

    public function import(ImportContext $ctx): void
    {
        foreach (self::TABLES as $table) {
            if (! $ctx->legacy()->tableExists($table)) {
                $ctx->report()->note(self::ENTITY.": legacy table {$table} missing (plugin manv-board not installed), skipped.");

                return;
            }
        }
        $this->ctx = $ctx;
        $this->warn = $this->created = $this->labels = [];
        $this->db = LegacyDbClock::for($ctx->legacy(), $ctx->report());
        $ctx->report()->note(self::ENTITY.': '.$this->db->describe());
        $this->users = $ctx->ids()->forEntity('user');
        $this->accounts = DB::table('users')->leftJoin('personnel', 'personnel.id', '=', 'users.personnel_id')
            ->get(['users.id', 'users.name', 'personnel.id as personnel_id', 'personnel.full_name'])
            ->mapWithKeys(fn ($u) => [(int) $u->id => (object) [
                'personnel_id' => $u->personnel_id === null ? null : (int) $u->personnel_id,
                'name' => $u->full_name ?? $u->name,
            ]])->all();

        DB::transaction(function () {
            $this->incidents();
            $this->resources();
            $this->patients();
            $this->log();
        });

        foreach ($this->warn as $what => $ids) {
            $ctx->report()->warnRows($what, array_values(array_unique($ids)));
        }
    }

    private function incidents(): void
    {
        $this->each('intra_manv_lagen', 'manv_incident', 'manv_incidents', function (object $row, int $id, array &$problems) {
            $status = LegacyUrlMap::MANV_STATUSES[(string) $row->status] ?? null;
            if ($status === null) {
                $problems[] = "unknown status '".mb_substr((string) $row->status, 0, 20)."', set to active";
            }
            $created = $this->db->toApp($row->erstellt_am);
            $started = LegacyClock::toApp($row->einsatzbeginn);
            if ($started === null) {
                $problems[] = 'no usable start (einsatzbeginn), set to the creation time';
            }
            $created ??= $started ?? now()->toDateTimeString();
            $this->created[$id] = $created;
            if (trim((string) $row->einsatzende) !== '') {
                $problems[] = 'einsatzende set (legacy never wrote it), left empty';
            }
            $values = [
                'incident_number' => mb_substr((string) $row->einsatznummer, 0, 50),
                'location' => mb_substr((string) $row->einsatzort, 0, 255),
                'reason' => $row->einsatzanlass,
                'status' => $status ?? 'active',
                'started_at' => $started ?? $created,
                'ended_at' => null,
                'notes' => $row->notizen,
                'created_by' => $this->user($row->erstellt_von, 'creating account', $problems),
                'updated_by' => $this->user($row->geaendert_von, 'editing account', $problems),
                'created_at' => $created,
                'updated_at' => $this->db->toApp($row->geaendert_am) ?? $created,
            ];
            foreach (['lna' => 'LNA', 'orgl' => 'OrgL'] as $role => $label) {
                $name = (string) $row->{$role.'_name'};
                $name = trim($name) === '' ? '' : $name;
                $file = null;
                if ((int) $row->{$role.'_mitarbeiter_id'} > 0) {
                    $account = $this->accounts[$this->users[(string) $row->{$role.'_mitarbeiter_id'}] ?? 0] ?? null;
                    $file = $account?->personnel_id;
                    if ($file === null) {
                        $problems[] = "{$label} account without an imported personnel file, only the name kept";
                    } elseif ($name === '') {
                        $name = (string) $account->name;
                    }
                }
                $values[$role.'_personnel_id'] = $file;
                $values[$role.'_name'] = $name === '' ? null : mb_substr($name, 0, 255);
            }

            return $values;
        });
    }

    private function resources(): void
    {
        $incidents = $this->ctx->ids()->forEntity('manv_incident');
        $vehicles = [];
        foreach (DB::table('vehicles')->where('is_active', true)->orderBy('id')->get(['id', 'name']) as $v) {
            $key = self::key((string) $v->name);
            $vehicles[$key] = array_key_exists($key, $vehicles) ? 0 : (int) $v->id;
        }
        $taken = [];

        $this->each('intra_manv_ressourcen', 'manv_resource', 'manv_resources', function (object $row, int $id, array &$problems) use ($incidents, $vehicles, &$taken) {
            $incident = $incidents[(string) $row->manv_lage_id] ?? null;
            if ($incident === null) {
                return null;
            }
            $label = trim((string) $row->bezeichnung);
            $this->labels[(int) $row->manv_lage_id][self::key($label)] ??= $id;
            $unique = $label === '' ? "Ressource {$id}" : $label;
            for ($n = 2; isset($taken[$incident][self::key($unique)]); $n++) {
                $unique = mb_substr($label, 0, 240)." ({$n})";
            }
            if ($unique !== $label) {
                $problems[] = 'label empty or used twice in one incident, made unique';
            }
            $taken[$incident][self::key($unique)] = true;
            $type = self::RESOURCE_TYPES[(string) $row->typ] ?? null;
            if ($type === null) {
                $problems[] = "unknown type '".mb_substr((string) $row->typ, 0, 20)."', set to vehicle";
            }
            $status = self::RESOURCE_STATUSES[(string) $row->status] ?? null;
            if ($status === null) {
                $problems[] = "unknown status '".mb_substr((string) $row->status, 0, 20)."', set to available";
            }
            $vehicle = $label === '' ? null : ($vehicles[self::key($label)] ?? null);
            if ($vehicle === 0) {
                $problems[] = 'label matches several active vehicles, no vehicle linked';
            } elseif ($vehicle !== null && $unique !== $label) {
                $problems[] = 'duplicate label, stock vehicle linked to the first resource of the incident only';
                $vehicle = null;
            }
            $notes = $row->notizen;
            $crew = $row->besatzung === null ? null : (string) $row->besatzung;
            if ($crew !== null && mb_strlen($crew) > 255) {
                $problems[] = 'crew over 255 characters, cut (full text appended to the notes)';
                $notes = self::appendNote($notes, 'Besatzung (Altbestand)', $crew);
                $crew = mb_substr($crew, 0, 255);
            }
            $created = $this->db->toApp($row->erstellt_am) ?? $this->created[(int) $row->manv_lage_id] ?? now()->toDateTimeString();

            return [
                'manv_incident_id' => $incident,
                'type' => $type ?? 'vehicle',
                'label' => mb_substr($unique, 0, 255),
                'callsign' => $row->rufname,
                'vehicle_type' => $row->fahrzeugtyp,
                'vehicle_id' => $vehicle ?: null,
                'location' => $row->lokalisation,
                'status' => $status ?? 'available',
                'crew' => $crew,
                'notes' => $notes,
                'departed_at' => null,
                'created_at' => $created,
                'updated_at' => $this->db->toApp($row->geaendert_am) ?? $created,
            ];
        });
    }

    private function patients(): void
    {
        $incidents = $this->ctx->ids()->forEntity('manv_incident');
        $resources = $this->ctx->ids()->forEntity('manv_resource');
        $hospitals = [];
        foreach (DB::table('pois')->whereIn('type', Poi::HOSPITAL_TYPES)->orderBy('id')->get(['id', 'name']) as $p) {
            $key = self::key((string) $p->name);
            $hospitals[$key] = array_key_exists($key, $hospitals) ? 0 : (int) $p->id;
        }
        $taken = [];

        $this->each('intra_manv_patienten', 'manv_patient', 'manv_patients', function (object $row, int $id, array &$problems) use ($incidents, $resources, $hospitals, &$taken) {
            $lage = (int) $row->manv_lage_id;
            $incident = $incidents[(string) $lage] ?? null;
            if ($incident === null) {
                return null;
            }
            $base = trim((string) $row->patienten_nummer);
            $number = $base === '' ? "#{$id}" : $base;
            for ($n = 2; isset($taken[$incident][self::key($number)]); $n++) {
                $number = mb_substr($base, 0, 45)."-{$n}";
            }
            if ($number !== $base) {
                $problems[] = 'number empty or used twice in one incident, made unique (-2, -3 ...)';
            }
            $taken[$incident][self::key($number)] = true;

            $triage = (string) $row->sichtungskategorie;
            if ($triage === 'tot') {
                $problems[] = "triage 'tot' merged into SK5";
                $triage = 'SK5';
            } elseif ($row->sichtungskategorie !== null && ! in_array($triage, self::TRIAGE, true)) {
                $problems[] = "unknown triage '".mb_substr($triage, 0, 20)."', imported as untriaged";
            }
            $sex = self::SEXES[(string) $row->geschlecht] ?? null;
            if ($sex === null && $row->geschlecht !== null) {
                $problems[] = "unknown sex '".mb_substr((string) $row->geschlecht, 0, 20)."', set to unknown";
            }
            $birth = trim((string) $row->geburtsdatum);
            if ($birth !== '' && (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $birth, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
                $problems[] = 'unusable birth date, left empty';
                $birth = '';
            }

            $callsign = trim((string) $row->transportmittel_rufname);
            $legacyResource = $callsign === '' ? null : ($this->labels[$lage][self::key($callsign)] ?? null);
            $resource = $legacyResource === null ? null : ($resources[(string) $legacyResource] ?? null);
            $departed = $this->db->toApp($row->transport_abfahrt);
            if ($callsign !== '' && $resource === null) {
                $problems[] = $departed !== null
                    ? 'departed, its resource no longer exists (legacy deleted it on departure): kept as text snapshot'
                    : 'assigned resource not found in its incident (renamed or deleted): kept as text snapshot';
            }
            $destination = trim((string) $row->transportziel);
            $poi = $destination === '' ? null : ($hospitals[self::key($destination)] ?? null);

            $notes = $row->notizen;
            if (trim((string) $row->massnahmen) !== '') {
                $problems[] = 'measures (massnahmen, no field any more) appended to the notes';
                $notes = self::appendNote($notes, 'Maßnahmen (Altbestand)', (string) $row->massnahmen);
            }
            if (trim((string) $row->transport_ankunft) !== '') {
                $problems[] = 'arrival time (transport_ankunft, no field any more) appended to the notes';
                $notes = self::appendNote($notes, 'Ankunft (Altbestand)', (string) $row->transport_ankunft);
            }
            $created = $this->db->toApp($row->erstellt_am) ?? $this->created[$lage] ?? now()->toDateTimeString();

            return [
                'manv_incident_id' => $incident,
                'number' => mb_substr($number, 0, 50),
                'last_name' => $row->name,
                'first_name' => $row->vorname,
                'birth_date' => $birth === '' ? null : $birth,
                'sex' => $sex ?? 'unknown',
                'triage' => in_array($triage, self::TRIAGE, true) ? $triage : null,
                'triaged_at' => LegacyClock::toApp($row->sichtungskategorie_zeit),
                'triaged_by' => $this->user($row->sichtungskategorie_geaendert_von, 'triaging account', $problems),
                'manv_resource_id' => $resource,
                'vehicle_type' => $row->transportmittel,
                'callsign' => $callsign === '' ? null : $callsign,
                'vehicle_location' => $row->fahrzeug_lokalisation,
                'destination' => $destination === '' ? null : $destination,
                'destination_poi_id' => $poi ?: null,
                'departed_at' => $departed,
                'injuries' => $row->verletzungen,
                'notes' => $notes,
                'created_by' => $this->user($row->erstellt_von, 'creating account', $problems),
                'updated_by' => $this->user($row->geaendert_von, 'editing account', $problems),
                'created_at' => $created,
                'updated_at' => $this->db->toApp($row->geaendert_am) ?? $created,
            ];
        });
    }

    private function log(): void
    {
        $incidents = $this->ctx->ids()->forEntity('manv_incident');
        $subjects = ['patient' => ['patient', $this->ctx->ids()->forEntity('manv_patient')], 'ressource' => ['resource', $this->ctx->ids()->forEntity('manv_resource')]];

        $this->each('intra_manv_log', 'manv_log', 'manv_log', function (object $row, int $id, array &$problems) use ($incidents, $subjects) {
            $incident = $incidents[(string) $row->manv_lage_id] ?? null;
            if ($incident === null) {
                return null;
            }
            $occurred = $this->db->toApp($row->timestamp);
            if ($occurred === null) {
                $problems[] = 'no usable time, set to the creation time of its incident';
            }
            $user = $this->user($row->benutzer_id, 'account', $problems);
            $name = trim((string) $row->benutzer_name);
            if ($name === '' && $user !== null) {
                $name = (string) ($this->accounts[$user]->name ?? '');
            }
            [$type, $subject] = [null, null];
            if ($row->referenz_typ !== null && $row->referenz_id !== null) {
                [$type, $map] = $subjects[(string) $row->referenz_typ] ?? [null, []];
                $subject = $map[(string) $row->referenz_id] ?? null;
                if ($subject === null) {
                    $problems[] = 'reference to a patient or resource that was not imported (or no longer exists), left empty';
                    $type = null;
                }
            }

            return [
                'manv_incident_id' => $incident,
                'occurred_at' => $occurred ?? $this->created[(int) $row->manv_lage_id] ?? now()->toDateTimeString(),
                'action' => mb_substr((string) $row->aktion, 0, 100),
                'description' => (string) $row->beschreibung,
                'user_id' => $user,
                'user_name' => $name === '' ? null : mb_substr($name, 0, 255),
                'subject_type' => $type,
                'subject_id' => $subject,
            ];
        });
    }

    /**
     * Imports $table row by row (by id, in blocks): $values gives the columns or null when the row's incident was not
     * imported (skipped). Each row is written in its own transaction; its warnings count only when it was written.
     *
     * @param  \Closure(object, int, list<string>&): ?array<string, mixed>  $values
     */
    private function each(string $table, string $entity, string $target, \Closure $values): void
    {
        $this->ctx->legacy()->table($table)->chunkById(self::CHUNK, function ($rows) use ($table, $entity, $target, $values) {
            $report = $this->ctx->report();
            foreach ($rows as $row) {
                $report->read($entity);
                $id = (int) $row->id;
                $problems = [];
                try {
                    $columns = $values($row, $id, $problems);
                    if ($columns === null) {
                        $report->skipped($entity, 'incident not imported', ['legacy_id' => $id]);

                        continue;
                    }
                    DB::transaction(fn () => $this->ctx->ids()->write($entity, $id, $target, $columns));
                } catch (Throwable $e) {
                    $report->failed($entity, "legacy {$table} {$id}: ".ImportReport::describe($e));

                    continue;
                }
                $report->written($entity);
                foreach (array_unique($problems) as $what) {
                    $this->warn["{$entity}(s): {$what}"][] = $id;
                }
            }
        });
    }

    /**
     * The new account for a legacy account id; null (and a counted warning) when it was not imported or its mapped account
     * no longer exists in the new instance (stale legacy_id_map entry).
     *
     * @param  list<string>  $problems
     */
    private function user(mixed $legacy, string $what, array &$problems): ?int
    {
        if ($legacy === null || (int) $legacy <= 0) {
            return null;
        }
        $user = $this->users[(string) $legacy] ?? null;
        if ($user !== null && ! isset($this->accounts[$user])) {
            $user = null;
        }
        if ($user === null) {
            $problems[] = "{$what} not imported, left empty";
        }

        return $user;
    }

    private static function key(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private static function appendNote(mixed $notes, string $label, string $text): string
    {
        $notes = (string) $notes;

        return (trim($notes) === '' ? '' : $notes."\n\n")."{$label}: {$text}";
    }
}
