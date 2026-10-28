<?php

namespace App\Import;

use App\Http\Requests\SaveVehicleRequest;
use App\Vehicles\TacticalSymbol;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * intra_fahrzeuge => vehicles, mapped by legacy_id_map (entity `vehicle`). The module importers find vehicles
 * through that map.
 *
 * - `active` => `is_active` unchanged: a vehicle the legacy defect logic switched off stays off; the
 *   report counts the inactive vehicles with an open blocking defect so the operator can switch them on by hand.
 * - `rd_type` outside 0-3 => 0, `kennzeichen` '' => null, `priority` => `sort_order`.
 * - `allowed_jobs`: the comma list becomes a list with the rules of the vehicle form (trimmed, duplicates and
 *   over-long jobs dropped). The legacy FIND_IN_SET did not trim, so "ems, fire" never matched "fire"; it does now.
 * - Tactical symbol: only allowlisted library values survive (TacticalSymbol); unknown ones are dropped, never guessed.
 * - `current_status` > 6 stays as text. Clocks per column: `status_updated_at` is PHP time (LegacyClock), except
 *   `status_source` incident (firetab wrote NOW(), LegacyDbClock); `created_at` is a TIMESTAMP (instant).
 * - Duplicate `name`/`identifier` stay (the EMD sync took the first one); one warning each.
 */
final class VehicleImporter implements Importer
{
    private const ENTITY = 'vehicle';

    private const CHUNK = 500;

    private const SOURCE_MAX = 20;

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return ['poi'];
    }

    public function requiredTables(): array
    {
        return ['intra_fahrzeuge', 'intra_fahrzeuge_defects'];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $dbClock = LegacyDbClock::for($ctx->legacy(), $report);
        $report->note(self::ENTITY.': '.$dbClock->describe());
        $pois = $ctx->ids()->forEntity('poi');
        /** @var array<string, list<string>> $warn summary warning => legacy ids */
        $warn = [];
        /** @var array<string, list<string>> $names lower-cased name/identifier => legacy ids */
        $names = ['name' => [], 'identifier' => []];

        DB::transaction(function () use ($ctx, $report, $dbClock, $pois, &$warn, &$names) {
            $ctx->legacy()->tableWithInstants('intra_fahrzeuge', ['created_at'])->chunkById(self::CHUNK, function ($rows) use ($ctx, $report, $dbClock, $pois, &$warn, &$names) {
                foreach ($rows as $row) {
                    $report->read(self::ENTITY);
                    $legacyId = (string) $row->id;
                    $problems = [];
                    try {
                        $values = $this->values($row, $pois, $dbClock, $problems);
                        DB::transaction(fn () => $ctx->ids()->write(self::ENTITY, $legacyId, 'vehicles', $values));
                    } catch (Throwable $e) {
                        $report->failed(self::ENTITY, "legacy vehicle {$legacyId}: ".ImportReport::describe($e));

                        continue;
                    }
                    $report->written(self::ENTITY);
                    foreach (array_unique($problems) as $what) {
                        $warn[$what][] = $legacyId;
                    }
                    foreach (['name', 'identifier'] as $column) {
                        $names[$column][mb_strtolower(trim((string) $row->{$column}))][] = $legacyId;
                    }
                }
            });
        });

        foreach ($warn as $what => $legacyIds) {
            $report->warnRows("vehicle(s): {$what}", $legacyIds);
        }
        foreach ($names as $column => $groups) {
            $dupes = array_merge(...array_values(array_filter($groups, fn (array $ids) => count($ids) > 1)) ?: [[]]);
            $report->warnRows("vehicle(s) share their {$column} with another vehicle, all kept", $dupes);
        }
        $blocked = $ctx->legacy()->table('intra_fahrzeuge as f')->where('f.active', 0)
            ->whereExists(fn ($q) => $q->from('intra_fahrzeuge_defects as d')->whereColumn('d.vehicle_id', 'f.id')
                ->where('d.vehicle_operable', 0)->where('d.status', '!=', 'resolved'))
            ->orderBy('f.id')->pluck('f.id')->map(fn ($id) => (string) $id)->all();
        if ($blocked !== []) {
            $report->note(count($blocked).' inactive vehicle(s) have an open blocking defect; the legacy defect logic probably switched them off. '
                .'They stay inactive (is_active is the admin switch, resolving a defect no longer switches it on); switch them on by hand '
                .'if wanted (legacy ids: '.ImportReport::idList($blocked).')');
        }
    }

    /**
     * @param  array<int|string, int>  $pois
     * @param  list<string>  $problems
     * @return array<string, mixed>
     */
    private function values(object $row, array $pois, LegacyDbClock $dbClock, array &$problems): array
    {
        $service = (int) $row->rd_type;
        if ($service < 0 || $service > 3) {
            $problems[] = "service (rd_type) {$service} is outside 0-3, set to 0";
            $service = 0;
        }
        $station = null;
        if ($row->stationierung_poi_id !== null && (int) $row->stationierung_poi_id !== 0) {
            $station = $pois[(string) $row->stationierung_poi_id] ?? null;
            if ($station === null) {
                $problems[] = 'station POI was not imported (or no longer exists), imported without station';
            }
        }
        $fms = trim((string) $row->current_status);
        if ($fms !== '' && ! (ctype_digit($fms) && (int) $fms <= 6)) {
            $problems[] = 'FMS status outside 0-6, kept as text';
        }
        $source = trim((string) $row->status_source);
        if ($source !== '' && ! in_array($source, ['manual', 'no_dispatch', 'incident'], true)) {
            $problems[] = 'unknown FMS status source, kept'.(mb_strlen($source) > self::SOURCE_MAX ? ' (cut to '.self::SOURCE_MAX.' characters)' : '');
            $source = mb_substr($source, 0, self::SOURCE_MAX);
        }
        $statusAt = $source === 'incident' ? $dbClock->toApp($row->status_updated_at) : LegacyClock::toApp($row->status_updated_at);
        $created = LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString();
        $plate = trim((string) $row->kennzeichen);

        $symbol = self::symbol($row, $problems);
        $symbolName = self::text($row->tz_name, 'tz_name', $problems);

        return [
            'sort_order' => (int) $row->priority,
            'identifier' => (string) $row->identifier,
            'name' => (string) $row->name,
            'type' => (string) $row->veh_type,
            'service' => $service,
            'license_plate' => $plate === '' ? null : $plate,
            'is_active' => (bool) $row->active,
            'allowed_jobs' => self::jobs($row->allowed_jobs, $problems),
            'station_poi_id' => $station,
            'tactical_symbol' => $symbol === null ? null : json_encode($symbol, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'tactical_symbol_name' => $symbolName,
            'fms_status' => $fms === '' ? null : $fms,
            'fms_status_at' => $statusAt,
            'fms_status_source' => $source === '' ? null : $source,
            'created_at' => $created,
            'updated_at' => $created,
        ];
    }

    /**
     * JSON text of the job list (null = all jobs), parsed like the vehicle form.
     *
     * @param  list<string>  $problems
     */
    private static function jobs(?string $raw, array &$problems): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $parsed = SaveVehicleRequest::parseJobs($raw) ?? [];
        $jobs = SaveVehicleRequest::fitJobs($parsed);
        if ($jobs === []) {
            $problems[] = 'allowed_jobs had no usable job after cleaning, imported as open to all jobs';

            return null;
        }
        if (preg_match('/[\r\n]/', $raw) === 1) {
            $problems[] = 'allowed_jobs had line breaks, split into separate jobs (the legacy FIND_IN_SET read them as one job)';
        }
        if (preg_match('/[ \t\x{00A0}],|,[ \t\x{00A0}]|^[\s\x{00A0}]|[\s\x{00A0}]$/u', $raw) === 1) {
            $problems[] = 'allowed_jobs had spaces around the commas; trimmed, so those jobs match now (the legacy FIND_IN_SET did not trim)';
        }
        if (count($jobs) < count($parsed)) {
            $problems[] = 'allowed_jobs had jobs over 100 characters or more than 50 jobs, those were dropped';
        }

        return json_encode($jobs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }

    /**
     * The seven symbol columns of a legacy row as the stored symbol: cleaned like the form (TacticalSymbol::clean),
     * select values kept only when allowlisted, free texts cut to 100 characters. Null when nothing is left.
     * Also used for the legacy templates.
     *
     * @param  list<string>  $problems  "<field> '<value>' is not a known library value, dropped" etc.
     * @return ?array<string, string>
     */
    public static function symbol(object $row, array &$problems): ?array
    {
        $raw = [];
        foreach (TacticalSymbol::KEYS as $key) {
            $raw[$key] = $row->{$key} ?? null;
        }
        $symbol = [];
        foreach (TacticalSymbol::clean($raw) as $key => $value) {
            $value = (string) $value;
            if (isset(TacticalSymbol::options()[$key])) {
                if (in_array($value, TacticalSymbol::options()[$key], true)) {
                    $symbol[$key] = $value;
                } else {
                    $problems[] = "tactical symbol {$key} '".mb_substr($value, 0, 100)."' is not a known library value, dropped";
                }
            } elseif (($text = self::text($value, $key, $problems)) !== null) {
                $symbol[$key] = $text;
            }
        }

        return $symbol === [] ? null : $symbol;
    }

    /**
     * A free symbol text without NUL and CDATA end markers, trimmed, at most 100 characters; null when empty.
     *
     * @param  list<string>  $problems
     */
    private static function text(mixed $value, string $field, array &$problems): ?string
    {
        $text = trim(str_replace(["\0", ']]>'], '', (string) $value));
        if (mb_strlen($text) > TacticalSymbol::MAX_TEXT) {
            $problems[] = "{$field} longer than ".TacticalSymbol::MAX_TEXT.' characters, cut';
            $text = rtrim(mb_substr($text, 0, TacticalSymbol::MAX_TEXT));
        }

        return $text === '' ? null : $text;
    }
}
