<?php

namespace App\Import;

use App\Models\VehicleDefect;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * intra_fahrzeuge_defects => vehicle_defects, mapped by legacy_id_map (entity `vehicle_defect`).
 *
 * - `category`: the legacy German keys map 1:1 to the new ones; anything else becomes `other` (counted).
 * - `reported_by` 0 (crew without account) or an account that was not imported => null. `reporter_name` then comes
 *   from the `created` history text "(Gemeldet durch: NAME)", else from the legacy account's name, else stays null.
 * - `assigned_to`/`resolved_by` via the user map, else null (counted). `vehicle_operable` => `is_operable`.
 * - Only resolved defects keep `resolved_by`/`resolved_at`/`resolution_note` (a reopened legacy defect kept them; the new
 *   flow clears them on reopen); an empty note becomes null.
 * - `created_at`/`updated_at` are TIMESTAMPs (instants); `resolved_at` was written with NOW() (LegacyDbClock).
 */
final class VehicleDefectImporter implements Importer
{
    private const ENTITY = 'vehicle_defect';

    private const CHUNK = 500;

    public const CATEGORIES = [
        'aufbau_karosserie' => 'body', 'ausbau' => 'fit_out', 'batterie' => 'battery', 'beleuchtung' => 'lighting',
        'bremsen' => 'brakes', 'elektrik' => 'electrics', 'fahrwerk' => 'chassis', 'getriebe' => 'gearbox',
        'motor' => 'engine', 'reifen' => 'tyres', 'service_pruefintervall' => 'service', 'signalanlage' => 'warning_system',
        'sonstiges' => 'other', 'windschutzscheibe' => 'windscreen',
    ];

    private const NAME_MAX = 200;

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return ['vehicle', 'user'];
    }

    public function requiredTables(): array
    {
        return ['intra_fahrzeuge_defects', 'intra_fahrzeuge_defect_log', 'intra_users'];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $dbClock = LegacyDbClock::for($ctx->legacy(), $report);
        $vehicles = $ctx->ids()->forEntity('vehicle');
        $users = $ctx->ids()->forEntity('user');
        /** @var array<string, list<string>> $warn */
        $warn = [];
        $orphans = [];

        DB::transaction(function () use ($ctx, $report, $dbClock, $vehicles, $users, &$warn, &$orphans) {
            $ctx->legacy()->tableWithInstants('intra_fahrzeuge_defects', ['created_at', 'updated_at'])->chunkById(self::CHUNK, function ($rows) use ($ctx, $report, $dbClock, $vehicles, $users, &$warn, &$orphans) {
                [$crewNames, $accountNames] = $this->reporterNames($ctx, $rows, $users);
                foreach ($rows as $row) {
                    $report->read(self::ENTITY);
                    $legacyId = (string) $row->id;
                    $vehicleId = $vehicles[(string) $row->vehicle_id] ?? null;
                    if ($vehicleId === null) {
                        $orphans[] = $legacyId;

                        continue;
                    }
                    $problems = [];
                    $category = self::CATEGORIES[(string) $row->category] ?? null;
                    if ($category === null) {
                        $problems[] = "unknown category '".mb_substr((string) $row->category, 0, 50)."', set to other";
                        $category = 'other';
                    }
                    $status = (string) $row->status;
                    if (! in_array($status, VehicleDefect::STATUSES, true)) {
                        $problems[] = "unknown status '".mb_substr($status, 0, 20)."', set to open";
                        $status = VehicleDefect::OPEN;
                    }
                    $reporter = $users[(string) $row->reported_by] ?? null;
                    $reporterName = null;
                    if ($reporter === null) {
                        $reporterName = $crewNames[$legacyId] ?? $accountNames[(string) $row->reported_by] ?? null;
                        $problems[] = ((int) $row->reported_by === 0 ? 'reported without an account' : 'reporter account not imported')
                            .($reporterName === null ? ', imported without reporter' : ', reporter name kept');
                    }
                    $resolved = $status === VehicleDefect::RESOLVED;
                    $created = LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString();
                    $values = [
                        'vehicle_id' => $vehicleId,
                        'title' => (string) $row->title,
                        'description' => $row->description,
                        'category' => $category,
                        'is_operable' => (bool) $row->vehicle_operable,
                        'status' => $status,
                        'reported_by' => $reporter,
                        'reporter_name' => $reporterName === null ? null : mb_substr($reporterName, 0, self::NAME_MAX),
                        'assigned_to' => self::user($row->assigned_to, $users, 'assigned account not imported, left unassigned', $problems),
                        'resolved_by' => $resolved ? self::user($row->resolved_by, $users, 'resolving account not imported, left empty', $problems) : null,
                        'resolved_at' => $resolved ? $dbClock->toApp($row->resolved_at) : null,
                        'resolution_note' => $resolved && trim((string) $row->resolution_note) !== '' ? $row->resolution_note : null,
                        'created_at' => $created,
                        'updated_at' => LegacyClock::instant($row, 'updated_at') ?? $created,
                    ];
                    try {
                        DB::transaction(fn () => $ctx->ids()->write(self::ENTITY, $legacyId, 'vehicle_defects', $values));
                    } catch (Throwable $e) {
                        $report->failed(self::ENTITY, "legacy defect {$legacyId}: ".ImportReport::describe($e));

                        continue;
                    }
                    $report->written(self::ENTITY);
                    foreach (array_unique($problems) as $what) {
                        $warn[$what][] = $legacyId;
                    }
                }
            });
        });

        if ($orphans !== []) {
            $report->skippedCount(self::ENTITY, count($orphans));
            $report->warnRows('defect(s) of vehicles that were not imported were skipped', $orphans);
        }
        foreach ($warn as $what => $legacyIds) {
            $report->warnRows("defect(s): {$what}", $legacyIds);
        }
    }

    /**
     * Names for defects without a mapped reporter: the crew name of the `created` history entry (legacy defect id =>
     * name) and the names of legacy accounts that were not imported (legacy user id => name).
     *
     * @param  iterable<object>  $rows
     * @param  array<int|string, int>  $users
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function reporterNames(ImportContext $ctx, iterable $rows, array $users): array
    {
        $defectIds = [];
        $accounts = [];
        foreach ($rows as $row) {
            if (! isset($users[(string) $row->reported_by])) {
                $defectIds[] = (int) $row->id;
                if ((int) $row->reported_by !== 0) {
                    $accounts[] = (int) $row->reported_by;
                }
            }
        }
        $crew = [];
        if ($defectIds !== []) {
            $logs = $ctx->legacy()->table('intra_fahrzeuge_defect_log')->whereIn('defect_id', $defectIds)
                ->where('action', 'created')->where('details', 'like', '%(Gemeldet durch: %')->orderBy('id')->get(['defect_id', 'details']);
            foreach ($logs as $log) {
                if (preg_match('/^.*\(Gemeldet durch: (.*)\)\s*$/su', (string) $log->details, $m) === 1 && trim($m[1]) !== '') {
                    $crew[(string) $log->defect_id] ??= trim($m[1]);
                }
            }
        }
        $names = [];
        if ($accounts !== []) {
            foreach ($ctx->legacy()->table('intra_users')->whereIn('id', array_unique($accounts))->get(['id', 'fullname', 'username']) as $u) {
                $name = trim((string) ($u->fullname ?: $u->username));
                if ($name !== '') {
                    $names[(string) $u->id] = $name;
                }
            }
        }

        return [$crew, $names];
    }

    /**
     * @param  array<int|string, int>  $users
     * @param  list<string>  $problems
     */
    private static function user(mixed $legacyUser, array $users, string $problem, array &$problems): ?int
    {
        if ($legacyUser === null || (int) $legacyUser === 0) {
            return null;
        }
        $id = $users[(string) $legacyUser] ?? null;
        if ($id === null) {
            $problems[] = $problem;
        }

        return $id;
    }
}
