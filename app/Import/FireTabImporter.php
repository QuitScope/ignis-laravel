<?php

namespace App\Import;

use App\Vehicles\TacticalSymbol;
use Carbon\Carbon;
use Closure;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\FireTab\Enums\AsuEventType;
use Modules\FireTab\Enums\AsuPosition;
use Modules\FireTab\Enums\AsuRole;
use Modules\FireTab\Models\FireMapMarker;
use Modules\FireTab\Models\FireMapZone;
use RuntimeException;
use Throwable;

/**
 * intra_fire_incidents / _incident_vehicles / _incident_sitreps / _incident_log => fire_incidents / fire_incident_vehicles /
 * fire_sitreps / fire_incident_log (module FireTab), id map entities fire_incident, fire_incident_vehicle, fire_sitrep,
 * fire_incident_log. Part 1; part 2 (asu*()): intra_fire_asu_trupps / _members /
 * _events and intra_fire_incident_asu => fire_asu_trupps / _members / _events / _protocols (id map fire_asu_trupp,
 * fire_asu_member, fire_asu_event, fire_asu_protocol). Part 3 (8c, map*()): intra_fire_incident_map_markers / _map_zones =>
 * fire_map_markers / fire_map_zones (id map fire_map_marker, fire_map_zone).
 *
 * - Clocks (3.3): started_at and report_time are UTC wall time already (the legacy timezone fix, core migration
 *   20250607000122, moved the old rows): taken as they are, never through IGNIS_IMPORT_LEGACY_TIMEZONE. checkLegacyState()
 *   refuses a legacy database without that migration. TIMESTAMP columns are instants, except created_at of incidents the
 *   EMD sync created (created_by empty and log `created` "Einsatz automatisch durch Sync erstellt"): UTC text written into
 *   a TIMESTAMP, read back as wall time of the legacy database clock and taken as UTC (a note counts them).
 * - Incidents: number verbatim; status 0-4 => new|reviewing|approved|insufficient|hidden (else new); leader via the
 *   personnel map; accounts via the user map (0, not imported or a stale map entry => empty, warning per column);
 *   finalized/archived without a time => updated_at; owner_type (legacy never wrote one) appended to the notes.
 * - Vehicles: vehicle via the map, else an external vehicle with the text snapshot; duplicates (incident, vehicle) from EMD
 *   races are merged into the row with the newest status (the others are skipped), so the unique index holds.
 * - Sitreps: source NULL => local, `leitstelle` => dispatch; synced_at = import time for every row (the dispatch sync must not send
 *   the legacy backlog again), kept on later runs.
 * - Log: action text verbatim (unknown keys counted), vehicle/operator/account via the maps (orphans empty, counted).
 * - The status queue is not imported (volatile): delivered rows are dropped, undelivered ones counted in a warning.
 * Every table in blocks of CHUNK (chunkById), one transaction per block, a savepoint per row: a failing row is rolled
 * back alone. Re-runnable by legacy_id_map, never deletes.
 */
final class FireTabImporter implements ChecksLegacyState, Importer
{
    private const ENTITY = 'firetab';

    private const CHUNK = 500;

    private const TABLES = ['intra_fire_incidents', 'intra_fire_incident_vehicles', 'intra_fire_incident_sitreps', 'intra_fire_incident_log'];

    public const TIMEZONE_FIX = '20250607000122';

    public const STATUSES = ['new', 'reviewing', 'approved', 'insufficient', 'hidden'];

    private const EMD_CREATED = 'Einsatz automatisch durch Sync erstellt';

    private const ACTIONS = ['created', 'vehicle_added', 'vehicle_removed', 'sitrep_added', 'finalized', 'status_changed', 'data_updated', 'archived', 'unarchived'];

    private ImportContext $ctx;

    private LegacyDbClock $db;

    /** @var array<string, list<int|string>> warning => legacy ids */
    private array $warn = [];

    /** @var array<int|string, int> legacy id => new id, existing rows only */
    private array $users = [];

    /** @var array<int|string, int> */
    private array $personnel = [];

    /** @var array<int|string, int> */
    private array $vehicles = [];

    /** @var array<int, bool> legacy incident ids the EMD sync created (=> true) */
    private array $emd = [];

    /** @var array<int, string> legacy incident id => created_at (fallback for children without a time) */
    private array $created = [];

    private string $now;

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return ['user', 'personnel', 'vehicle'];
    }

    public function requiredTables(): array
    {
        return [];
    }

    public function checkLegacyState(LegacyReader $legacy): void
    {
        if (! $legacy->tableExists('intra_fire_incidents') || ! $legacy->table('intra_fire_incidents')->exists()) {
            return;
        }
        if (! $legacy->tableExists('phinxlog') || ! $legacy->table('phinxlog')->where('version', self::TIMEZONE_FIX)->exists()) {
            throw new RuntimeException('FireTab: the legacy phinxlog does not contain migration '.self::TIMEZONE_FIX
                .' (timezone fix: started_at and sitrep times to UTC); without it those times are Berlin time and would be imported'
                .' one or two hours off. Bring the legacy system to its last state (php cli/intra.php migrate) before importing,'
                .' or leave firetab out (--only with the other entities).');
        }
    }

    public function import(ImportContext $ctx): void
    {
        foreach (self::TABLES as $table) {
            if (! $ctx->legacy()->tableExists($table)) {
                $ctx->report()->note(self::ENTITY.": legacy table {$table} missing (plugin firetab not installed), skipped.");

                return;
            }
        }
        $this->ctx = $ctx;
        $this->warn = $this->created = [];
        $this->now = now()->toDateTimeString();
        $this->db = LegacyDbClock::for($ctx->legacy(), $ctx->report());
        $this->users = $this->existing('user', 'users');
        $this->personnel = $this->existing('personnel', 'personnel');
        $this->vehicles = $this->existing('vehicle', 'vehicles');
        $this->emd = $ctx->legacy()->table('intra_fire_incident_log')->where('action_type', 'created')
            ->where('action_description', self::EMD_CREATED)->pluck('incident_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();

        $this->incidents();
        $this->vehicles();
        $this->sitreps();
        $this->log();
        $this->statusQueue();
        $this->asu();
        $this->map();

        foreach ($this->warn as $what => $ids) {
            $ctx->report()->warnRows($what, array_values(array_unique($ids)));
        }
    }

    private function incidents(): void
    {
        $emd = 0;
        $query = $this->ctx->legacy()->tableWithInstants('intra_fire_incidents', ['created_at', 'updated_at', 'finalized_at', 'archived_at']);
        $this->each('fire_incident', 'fire_incidents', $query, function (object $row, int $id, array &$problems) use (&$emd) {
            $status = is_numeric($row->status) ? (self::STATUSES[(int) $row->status] ?? null) : null;
            if ($status === null) {
                $problems[] = "unknown status '".mb_substr((string) $row->status, 0, 10)."', set to new";
            }
            $started = LegacyClock::toApp($row->started_at, new DateTimeZone('UTC'));
            if ($started === null && trim((string) $row->started_at) !== '') {
                $problems[] = 'unusable started_at, left empty';
            }
            $isEmd = isset($this->emd[$id]) && $row->created_by === null;
            $created = ($isEmd ? $this->utcWall($row, 'created_at') : LegacyClock::instant($row, 'created_at')) ?? $started ?? $this->now;
            $updated = LegacyClock::instant($row, 'updated_at') ?? $created;
            $this->created[$id] = $created;

            $leader = null;
            if ($row->leader_id !== null) {
                $leader = $this->personnel[(string) $row->leader_id] ?? null;
                if ($leader === null) {
                    $problems[] = 'leader (personnel file) not imported, left empty';
                }
            }
            $notes = self::text($row->notes);
            if (trim((string) $row->owner_type) !== '') {
                $problems[] = 'owner type (no field any more) appended to the notes';
                $notes = ($notes === null ? '' : $notes."\n\n").'Art Geschädigter/Eigentümer (Altbestand): '.trim((string) $row->owner_type);
            }
            [$finalizedAt, $finalizedBy] = $this->flag($row, 'finalized', $updated, $problems);
            [$archivedAt, $archivedBy] = $this->flag($row, 'archived', $updated, $problems);
            if ($isEmd) {
                $emd++;
            }

            return [
                'number' => self::text($row->incident_number),
                'location' => self::text($row->location),
                'location_x' => $row->location_x,
                'location_y' => $row->location_y,
                'keyword' => self::text($row->keyword),
                'caller_name' => self::text($row->caller_name),
                'caller_contact' => self::text($row->caller_contact),
                'owner_name' => self::text($row->owner_name),
                'owner_contact' => self::text($row->owner_contact),
                'started_at' => $started,
                'leader_personnel_id' => $leader,
                'status' => $status ?? 'new',
                'finalized_at' => $finalizedAt,
                'finalized_by' => $finalizedBy,
                'archived_at' => $archivedAt,
                'archived_by' => $archivedBy,
                'notes' => $notes,
                'created_by' => $this->user($row->created_by, 'creating account', $problems),
                'updated_by' => $this->user($row->updated_by, 'editing account', $problems),
                'created_at' => $created,
                'updated_at' => $updated,
            ];
        });
        if ($emd > 0) {
            $this->ctx->report()->note(self::ENTITY.": {$emd} incident(s) created by the EMD sync: created_at was UTC text in a TIMESTAMP, read as UTC wall time ({$this->db->zone->getName()} database clock).");
        }
    }

    private function vehicles(): void
    {
        $incidents = $this->ctx->ids()->forEntity('fire_incident');
        // Duplicates (incident, vehicle): legacy id of every row but the kept one => the kept row's legacy id. The kept row
        // is the one an earlier run already mapped (stable across runs, else the unique index breaks when the newest status
        // moves to another row), otherwise the one with the newest status; it always takes the newest row's data.
        $merged = $newest = [];
        $mapped = $this->existing('fire_incident_vehicle', 'fire_incident_vehicles');
        $pairs = $this->ctx->legacy()->table('intra_fire_incident_vehicles')->whereNotNull('vehicle_id')
            ->groupBy('incident_id', 'vehicle_id')->havingRaw('COUNT(*) > 1')->get(['incident_id', 'vehicle_id']);
        foreach ($pairs as $pair) {
            $ids = $this->ctx->legacy()->table('intra_fire_incident_vehicles')->where('incident_id', $pair->incident_id)->where('vehicle_id', $pair->vehicle_id)
                ->orderByRaw('status_updated_at IS NULL')->orderByDesc('status_updated_at')->orderByDesc('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
            $kept = current(array_filter($ids, fn (int $id) => isset($mapped[(string) $id]))) ?: $ids[0];
            foreach ($ids as $id) {
                if ($id !== $kept) {
                    $merged[$id] = $kept;
                }
            }
            if ($kept !== $ids[0]) {
                $newest[$kept] = $this->ctx->legacy()->tableWithInstants('intra_fire_incident_vehicles', ['created_at', 'status_updated_at'])->where('id', $ids[0])->first();
            }
        }

        $query = $this->ctx->legacy()->tableWithInstants('intra_fire_incident_vehicles', ['created_at', 'status_updated_at']);
        $this->each('fire_incident_vehicle', 'fire_incident_vehicles', $query, function (object $row, int $id, array &$problems) use ($incidents, $merged, $newest) {
            $incident = $incidents[(string) $row->incident_id] ?? null;
            if ($incident === null) {
                return 'incident not imported';
            }
            if (isset($merged[$id])) {
                return "duplicate of (incident, vehicle), merged into legacy row {$merged[$id]} (newest status wins)";
            }
            $row = $newest[$id] ?? $row;
            $vehicle = null;
            if ($row->vehicle_id !== null) {
                $vehicle = $this->vehicles[(string) $row->vehicle_id] ?? null;
                if ($vehicle === null) {
                    $problems[] = 'vehicle not imported, kept as external vehicle (text snapshot)';
                }
            }
            $statusAt = LegacyClock::instant($row, 'status_updated_at');
            $created = LegacyClock::instant($row, 'created_at') ?? $this->created[(int) $row->incident_id] ?? $this->now;

            return [
                'fire_incident_id' => $incident,
                'vehicle_id' => $vehicle,
                'name' => $row->vehicle_name,
                'identifier' => $row->vehicle_identifier,
                'radio_name' => $row->radio_name,
                'is_external' => (bool) $row->from_other_org || $vehicle === null,
                'fms_status' => $row->current_status === null ? null : mb_substr((string) $row->current_status, 0, 10),
                'fms_status_at' => $statusAt,
                'created_at' => $created,
                'updated_at' => $statusAt ?? $created,
            ];
        });
    }

    private function sitreps(): void
    {
        $incidents = $this->ctx->ids()->forEntity('fire_incident');
        $query = $this->ctx->legacy()->tableWithInstants('intra_fire_incident_sitreps', ['created_at']);
        $prepare = fn (Collection $rows) => DB::table('legacy_id_map')->join('fire_sitreps', 'fire_sitreps.id', '=', 'legacy_id_map.new_id')
            ->where('legacy_id_map.entity', 'fire_sitrep')->whereIn('legacy_id_map.legacy_id', $rows->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->pluck('fire_sitreps.synced_at', 'legacy_id_map.legacy_id')->all();

        $this->each('fire_sitrep', 'fire_sitreps', $query, function (object $row, int $id, array &$problems, array $synced) use ($incidents) {
            $incident = $incidents[(string) $row->incident_id] ?? null;
            if ($incident === null) {
                return 'incident not imported';
            }
            $reported = LegacyClock::toApp($row->report_time, new DateTimeZone('UTC'));
            $created = LegacyClock::instant($row, 'created_at');
            if ($reported === null) {
                $problems[] = 'unusable report_time, set to the creation time';
            }
            $source = match ($row->source) {
                null, '' => 'local',
                'leitstelle' => 'dispatch',
                default => null,
            };
            if ($source === null) {
                $problems[] = "unknown source '".mb_substr((string) $row->source, 0, 20)."', set to local";
            }
            $created ??= $reported ?? $this->created[(int) $row->incident_id] ?? $this->now;

            return [
                'fire_incident_id' => $incident,
                'reported_at' => $reported ?? $created,
                'text' => (string) $row->text,
                'reporter_name' => $row->vehicle_radio_name,
                'source' => $source ?? 'local',
                'is_important' => false,
                'synced_at' => $synced[(string) $id] ?? $this->now,
                'created_at' => $created,
                'updated_at' => $created,
            ];
        }, $prepare);
    }

    private function log(): void
    {
        $incidents = $this->ctx->ids()->forEntity('fire_incident');
        $query = $this->ctx->legacy()->tableWithInstants('intra_fire_incident_log', ['created_at']);
        $this->each('fire_incident_log', 'fire_incident_log', $query, function (object $row, int $id, array &$problems) use ($incidents) {
            $incident = $incidents[(string) $row->incident_id] ?? null;
            if ($incident === null) {
                return 'incident not imported';
            }
            $action = (string) $row->action_type;
            if (! in_array($action, self::ACTIONS, true) && preg_match('/^(marker|zone|asu)_/', $action) !== 1) {
                $problems[] = 'unknown action type, kept as text';
            }
            $vehicle = (int) $row->vehicle_id > 0 ? ($this->vehicles[(string) $row->vehicle_id] ?? null) : null;
            if ($vehicle === null && (int) $row->vehicle_id > 0) {
                $problems[] = 'vehicle not imported (orphan), left empty';
            }
            $operator = (int) $row->operator_id > 0 ? ($this->personnel[(string) $row->operator_id] ?? null) : null;
            if ($operator === null && (int) $row->operator_id > 0) {
                $problems[] = 'operator (personnel file) not imported (orphan), left empty';
            }
            $user = (int) $row->created_by > 0 ? ($this->users[(string) $row->created_by] ?? null) : null;
            if ($user === null && (int) $row->created_by > 0) {
                $problems[] = 'account not imported, left empty';
            }

            return [
                'fire_incident_id' => $incident,
                'action' => mb_substr($action, 0, 50),
                'description' => $row->action_description,
                'vehicle_id' => $vehicle,
                'personnel_id' => $operator,
                'user_id' => $user,
                'created_at' => LegacyClock::instant($row, 'created_at') ?? $this->created[(int) $row->incident_id] ?? $this->now,
            ];
        });
    }

    private function statusQueue(): void
    {
        if (! $this->ctx->legacy()->tableExists('intra_fire_status_queue')) {
            return;
        }
        $open = $this->ctx->legacy()->table('intra_fire_status_queue')->where('delivered', 0)->count();
        if ($open > 0) {
            $this->ctx->report()->warn("FireTab: {$open} undelivered status queue entr(y/ies) discarded (the queue is not imported; the dispatch never received these statuses).");
        }
    }

    // ---------------------------------------------------------------- part 2: Atemschutz

    private function asu(): void
    {
        foreach (['intra_fire_asu_trupps', 'intra_fire_asu_members', 'intra_fire_asu_events'] as $table) {
            if (! $this->ctx->legacy()->tableExists($table)) {
                $this->ctx->report()->note(self::ENTITY.": legacy table {$table} missing (legacy SCBA tables not migrated), SCBA events skipped.");
                $this->asuProtocols();

                return;
            }
        }
        $this->asuTrupps();
        $this->asuMembers();
        $this->asuEvents();
        $this->asuProtocols();
    }

    private function asuTrupps(): void
    {
        $incidents = $this->ctx->ids()->forEntity('fire_incident');
        $query = $this->ctx->legacy()->tableWithInstants('intra_fire_asu_trupps', ['created_at', 'updated_at']);
        $this->each('fire_asu_trupp', 'fire_asu_trupps', $query, function (object $row, int $id, array &$problems) use ($incidents) {
            $incident = $incidents[(string) $row->incident_id] ?? null;
            if ($incident === null) {
                return 'incident not imported';
            }
            $role = AsuRole::tryFrom((string) $row->role);
            if ($role === null) {
                $problems[] = 'unknown role, set to angriff';
            }
            $vehicle = null;
            if ($row->vehicle_id !== null) {
                $vehicle = $this->vehicles[(string) $row->vehicle_id] ?? null;
                if ($vehicle === null) {
                    $problems[] = 'vehicle not imported, left empty';
                }
            }
            $created = LegacyClock::instant($row, 'created_at') ?? $this->created[(int) $row->incident_id] ?? $this->now;

            return [
                'fire_incident_id' => $incident,
                'name' => (string) $row->name,
                'role' => ($role ?? AsuRole::Angriff)->value,
                'task' => $row->task,
                'expected_minutes' => (int) $row->expected_minutes,
                'vehicle_id' => $vehicle,
                'created_at' => $created,
                'updated_at' => LegacyClock::instant($row, 'updated_at') ?? $created,
            ];
        });
    }

    private function asuMembers(): void
    {
        $trupps = $this->existing('fire_asu_trupp', 'fire_asu_trupps');
        $this->each('fire_asu_member', 'fire_asu_members', $this->ctx->legacy()->table('intra_fire_asu_members'), function (object $row, int $id, array &$problems) use ($trupps) {
            $trupp = $trupps[(string) $row->trupp_id] ?? null;
            if ($trupp === null) {
                return 'Trupp not imported';
            }
            $position = AsuPosition::tryFrom((string) $row->position)
                ?? throw new RuntimeException("unknown position '".mb_substr((string) $row->position, 0, 10)."'");
            $personnel = null;
            if ((int) $row->mitarbeiter_id > 0) {
                $personnel = $this->personnel[(string) $row->mitarbeiter_id] ?? null;
                if ($personnel === null) {
                    $problems[] = 'personnel file not imported, left empty';
                }
            }

            return ['trupp_id' => $trupp, 'position' => $position->value, 'name' => (string) $row->name, 'personnel_id' => $personnel];
        });
    }

    private function asuEvents(): void
    {
        $incidents = $this->ctx->ids()->forEntity('fire_incident');
        $trupps = $this->existing('fire_asu_trupp', 'fire_asu_trupps');
        $members = $this->existing('fire_asu_member', 'fire_asu_members');
        $query = $this->ctx->legacy()->tableWithInstants('intra_fire_asu_events', ['created_at', 'voided_at']);
        $this->each('fire_asu_event', 'fire_asu_events', $query, function (object $row, int $id, array &$problems) use ($incidents, $trupps, $members) {
            $incident = $incidents[(string) $row->incident_id] ?? null;
            if ($incident === null) {
                return 'incident not imported';
            }
            $trupp = $trupps[(string) $row->trupp_id] ?? null;
            if ($trupp === null) {
                return 'Trupp not imported (orphan)';
            }
            $type = AsuEventType::tryFrom((string) $row->type)
                ?? throw new RuntimeException("unknown event type '".mb_substr((string) $row->type, 0, 20)."'");
            $member = null;
            if ($row->member_id !== null) {
                $member = $members[(string) $row->member_id] ?? null;
                if ($member === null) {
                    $problems[] = 'member not imported, left empty';
                }
            }
            $at = LegacyClock::instant($row, 'created_at');
            if ($at === null) {
                $problems[] = 'no time, set to the import time';
            }

            return [
                'fire_incident_id' => $incident,
                'trupp_id' => $trupp,
                'member_id' => $member,
                'type' => $type->value,
                'bar' => $row->bar,
                'note' => $row->note,
                'client_uuid' => (string) $row->client_uuid,
                'device' => $row->device,
                'occurred_at' => $at ?? $this->now,
                'received_at' => $at ?? $this->now,
                'voided_at' => LegacyClock::instant($row, 'voided_at'),
                'voided_by' => null,
            ];
        });
    }

    private function asuProtocols(): void
    {
        if (! $this->ctx->legacy()->tableExists('intra_fire_incident_asu')) {
            return;
        }
        $incidents = $this->ctx->ids()->forEntity('fire_incident');
        $query = $this->ctx->legacy()->tableWithInstants('intra_fire_incident_asu', ['created_at', 'updated_at']);
        $this->each('fire_asu_protocol', 'fire_asu_protocols', $query, function (object $row, int $id, array &$problems) use ($incidents) {
            $incident = $incidents[(string) $row->incident_id] ?? null;
            if ($incident === null) {
                return 'incident not imported';
            }
            $created = LegacyClock::instant($row, 'created_at') ?? $this->created[(int) $row->incident_id] ?? $this->now;

            return [
                'fire_incident_id' => $incident,
                'supervisor' => (string) $row->supervisor,
                'mission_location' => $row->mission_location,
                'mission_date' => $row->mission_date,
                'recorded_at' => LegacyClock::toApp($row->timestamp, new DateTimeZone('UTC')),
                'payload' => (string) $row->data,
                'created_at' => $created,
                'updated_at' => LegacyClock::instant($row, 'updated_at') ?? $created,
            ];
        });
    }

    // ---------------------------------------------------------------- part 3: Lagekarte

    private function map(): void
    {
        $incidents = $this->ctx->ids()->forEntity('fire_incident');
        foreach (['intra_fire_incident_map_markers', 'intra_fire_incident_map_zones'] as $table) {
            if (! $this->ctx->legacy()->tableExists($table)) {
                $this->ctx->report()->note(self::ENTITY.": legacy table {$table} missing, skipped.");

                continue;
            }
            $query = $this->ctx->legacy()->tableWithInstants($table, ['created_at']);
            $table === 'intra_fire_incident_map_markers' ? $this->mapMarkers($incidents, $query) : $this->mapZones($incidents, $query);
        }
    }

    /** @param  array<int|string, int>  $incidents */
    private function mapMarkers(array $incidents, Builder $query): void
    {
        $this->each('fire_map_marker', 'fire_map_markers', $query, function (object $row, int $id, array &$problems) use ($incidents) {
            $incident = $incidents[(string) $row->incident_id] ?? null;
            if ($incident === null) {
                return 'incident not imported';
            }
            $type = (string) $row->marker_type;
            if (preg_match('/^vehicle_(\d{1,10})$/', $type, $m) === 1) {
                $vehicle = $this->vehicles[$m[1]] ?? null;
                $type = $vehicle === null ? 'custom' : "vehicle_{$vehicle}";
                if ($vehicle === null) {
                    $problems[] = 'vehicle marker of a vehicle not imported, set to custom';
                }
            } elseif (! in_array($type, FireMapMarker::TYPES, true)) {
                $type = 'custom';
                $problems[] = 'unknown marker type, set to custom';
            }
            $columns = [
                'fire_incident_id' => $incident,
                'marker_type' => $type,
                'pos_x' => $this->percent($row->pos_x, 'position', $problems),
                'pos_y' => $this->percent($row->pos_y, 'position', $problems),
                'description' => $row->description,
            ];
            $symbol = VehicleImporter::symbol($row, $problems) ?? [];
            foreach (TacticalSymbol::KEYS as $key) {
                $columns[$key] = $symbol[$key] ?? null;
            }
            $name = property_exists($row, 'name') ? trim(str_replace(["\0", ']]>'], '', (string) $row->name)) : '';
            $columns['name'] = $name === '' ? null : self::cut($name, TacticalSymbol::MAX_TEXT, 'name', $problems);

            return $columns + $this->mapAttribution($row, $problems);
        });
    }

    /** @param  array<int|string, int>  $incidents */
    private function mapZones(array $incidents, Builder $query): void
    {
        $this->each('fire_map_zone', 'fire_map_zones', $query, function (object $row, int $id, array &$problems) use ($incidents) {
            $incident = $incidents[(string) $row->incident_id] ?? null;
            if ($incident === null) {
                return 'incident not imported';
            }
            $decoded = json_decode((string) $row->points, true);
            $valid = is_array($decoded) && array_is_list($decoded) && count($decoded) >= 3;
            foreach ($valid ? $decoded : [] as $p) {
                $valid = $valid && is_array($p) && isset($p['x'], $p['y']) && is_numeric($p['x']) && is_numeric($p['y'])
                    && $p['x'] >= 0 && $p['x'] <= 100 && $p['y'] >= 0 && $p['y'] <= 100;
            }
            if (! $valid) {
                $problems[] = 'unusable points (need a list of at least 3 {x,y} in 0-100), kept hidden (is_valid = false)';
            }
            $color = (string) $row->color;
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
                $problems[] = 'colour not #rrggbb, set to #dc3545';
                $color = '#dc3545';
            } elseif (! in_array(strtolower($color), FireMapZone::COLORS, true)) {
                $problems[] = 'colour outside the 9 form colours, kept';
            }

            return [
                'fire_incident_id' => $incident,
                'name' => self::cut((string) $row->name, 255, 'name', $problems),
                'description' => $row->description,
                'points' => $valid
                    ? json_encode(array_map(fn (array $p) => ['x' => round((float) $p['x'], 2), 'y' => round((float) $p['y'], 2)], $decoded), JSON_THROW_ON_ERROR)
                    : json_encode($decoded ?? (string) $row->points, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                'color' => strtolower($color),
                'is_valid' => $valid,
            ] + $this->mapAttribution($row, $problems);
        });
    }

    /**
     * created_by is an ACCOUNT id (the legacy FK pointed to intra_mitarbeiter, the old UI showed that file's name); operator_id
     * a personnel file, vehicle_id a vehicle. Not imported or stale => empty, warning.
     *
     * @param  list<string>  $problems
     * @return array<string, mixed>
     */
    private function mapAttribution(object $row, array &$problems): array
    {
        $operator = property_exists($row, 'operator_id') ? $row->operator_id : null;
        $personnel = (int) $operator > 0 ? ($this->personnel[(string) $operator] ?? null) : null;
        if ($personnel === null && (int) $operator > 0) {
            $problems[] = 'operator (personnel file) not imported, left empty';
        }
        $vehicle = (int) $row->vehicle_id > 0 ? ($this->vehicles[(string) $row->vehicle_id] ?? null) : null;
        if ($vehicle === null && (int) $row->vehicle_id > 0) {
            $problems[] = 'vehicle not imported, left empty';
        }
        $created = LegacyClock::instant($row, 'created_at') ?? $this->created[(int) $row->incident_id] ?? $this->now;

        return [
            'created_by_user_id' => $this->user($row->created_by, 'creating account', $problems),
            'personnel_id' => $personnel,
            'vehicle_id' => $vehicle,
            'created_at' => $created,
            'updated_at' => $created,
        ];
    }

    /**
     * Map percent: clamped to 0-100, 2 decimals (warning when clamped).
     *
     * @param  list<string>  $problems
     */
    private function percent(mixed $value, string $what, array &$problems): float
    {
        $v = round((float) $value, 2);
        if ($v < 0 || $v > 100) {
            $problems[] = "{$what} outside 0-100, clamped";
        }

        return max(0.0, min(100.0, $v));
    }

    /**
     * Text cut to the column length (warning).
     *
     * @param  list<string>  $problems
     */
    private static function cut(mixed $value, int $max, string $what, array &$problems): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;
        if (mb_strlen($value) > $max) {
            $problems[] = "{$what} longer than {$max} characters, cut";

            return mb_substr($value, 0, $max);
        }

        return $value;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Imports $query by id in blocks of CHUNK: one transaction per block, a savepoint per row. $values returns the columns
     * (written to $table, mapped as $entity) or why the row is skipped; it may throw (the row fails alone). Mapping
     * happens inside the row's try. Its problems count only when written.
     *
     * @param  Closure(object, int, list<string>&, array<mixed>): (array<string, mixed>|string)  $values
     * @param  ?Closure(Collection<int, \stdClass>): array<mixed>  $prepare
     */
    private function each(string $entity, string $table, Builder $query, Closure $values, ?Closure $prepare = null): void
    {
        $report = $this->ctx->report();
        $query->chunkById(self::CHUNK, function (Collection $rows) use ($entity, $table, $values, $prepare, $report) {
            $context = $prepare === null ? [] : $prepare($rows);
            DB::transaction(function () use ($rows, $entity, $table, $values, $context, $report) {
                foreach ($rows as $row) {
                    $report->read($entity);
                    $id = (int) $row->id;
                    $problems = [];
                    try {
                        $skip = DB::transaction(function () use ($values, $row, $id, &$problems, $context, $entity, $table) {
                            $columns = $values($row, $id, $problems, $context);
                            if (is_string($columns)) {
                                return $columns;
                            }
                            $this->ctx->ids()->write($entity, $id, $table, $columns);

                            return null;
                        });
                    } catch (Throwable $e) {
                        $report->failed($entity, "legacy {$entity} {$id}: ".ImportReport::describe($e));

                        continue;
                    }
                    if ($skip !== null) {
                        $report->skippedCount($entity, 1);
                        $this->warn["{$entity}(s) skipped: {$skip}"][] = $id;

                        continue;
                    }
                    $report->written($entity);
                    foreach (array_unique($problems) as $what) {
                        $this->warn["{$entity}(s): {$what}"][] = $id;
                    }
                }
            });
        }, 'id');
    }

    /**
     * finalized/archived flag with its time and account: [time, account]; set without a time => $updated (warning).
     *
     * @param  list<string>  $problems
     * @return array{0: ?string, 1: ?int}
     */
    private function flag(object $row, string $flag, string $updated, array &$problems): array
    {
        if (! (bool) $row->{$flag}) {
            return [null, null];
        }
        $at = LegacyClock::instant($row, $flag.'_at');
        if ($at === null) {
            $problems[] = "{$flag} without a time, set to updated_at";
        }

        return [$at ?? $updated, $this->user($row->{$flag.'_by'}, "{$flag} by account", $problems)];
    }

    /**
     * The new account for a legacy account column; null when empty, null and a warning for 0, an account that was not
     * imported or a stale map entry (the mapped account no longer exists).
     *
     * @param  list<string>  $problems
     */
    private function user(mixed $legacy, string $what, array &$problems): ?int
    {
        if ($legacy === null) {
            return null;
        }
        $user = (int) $legacy > 0 ? ($this->users[(string) $legacy] ?? null) : null;
        if ($user === null) {
            $problems[] = "{$what} not imported (or 0), left empty";
        }

        return $user;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function utcWall(object $row, string $column): ?string
    {
        $seconds = $row->{$column.'__unix'} ?? null;
        if ($seconds === null || (float) $seconds <= 0) {
            return null;
        }

        return LegacyClock::toApp(Carbon::createFromTimestamp((int) $seconds, $this->db->zone)->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
    }

    /**
     * legacy id => new id of $entity, only rows that still exist (a stale map entry counts as not imported).
     *
     * @return array<int|string, int>
     */
    private function existing(string $entity, string $table): array
    {
        return DB::table('legacy_id_map')->join($table, "{$table}.id", '=', 'legacy_id_map.new_id')->where('legacy_id_map.entity', $entity)
            ->pluck("{$table}.id", 'legacy_id_map.legacy_id')->map(fn ($id) => (int) $id)->all();
    }
}
