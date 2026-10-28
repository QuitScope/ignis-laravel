<?php

namespace App\Vehicles;

use App\Models\Vehicle;
use App\Models\VehicleRegistryEntry;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Takes the vehicle registry ef_bridge sends on request (legacy EmdSyncController::importVehicleRegistry). A run
 * replaces every `pending` entry; accepted and rejected ones stay. A row needs a non-empty `value` (the radio call
 * name); everything else is optional. Rows that cannot be stored (no value, not an array, invalid UTF-8, more than
 * MAX_ROW_BYTES encoded, nested deeper than MAX_DEPTH) are skipped and counted. Texts are cut to their columns.
 *
 * Called from POST api/emd/sync (EmdSync); no route of its own (docs/dev/vehicle-registry.md).
 */
final class VehicleRegistryIntake
{
    public const MAX_ROWS = 5000;

    public const MAX_ROW_BYTES = 16 * 1024;

    private const MAX_DEPTH = 8;

    private const EMS_PHYSICIAN = ['NEF', 'NAW', 'ITW', 'RTH', 'ITH'];

    private const EMS = ['RTW', 'KTW', 'NTW', 'N-KTW', 'NKTW', 'S-RTW'];

    private const TEXTS = [
        'type' => ['type', 255],
        'department' => ['department', 255],
        'valuelong' => ['value_long', 255],
        'job' => ['job', 255],
        'image' => ['image', 100],
        'funkkanal' => ['radio_channel', 50],
    ];

    private const CHUNK = 500;

    public const MAX_INSERT_BYTES = 1024 * 1024;

    public function __construct(private readonly Settings $settings) {}

    /** @param array<array-key, mixed> $rows the decoded `vehicle_registry` list */
    public function accept(array $rows): IntakeResult
    {
        $received = count($rows);
        if ($received > self::MAX_ROWS) {
            return new IntakeResult($received, 0, 0, IntakeResult::TOO_MANY_ROWS);
        }

        $now = now();
        $records = [];
        foreach ($rows as $row) {
            $record = is_array($row) ? $this->record($row) : null;
            if ($record !== null) {
                $records[] = $record + ['status' => VehicleRegistryEntry::PENDING, 'created_at' => $now];
            }
        }

        DB::transaction(function () use ($records) {
            VehicleRegistryEntry::where('status', VehicleRegistryEntry::PENDING)->delete();
            $chunk = [];
            $bytes = 0;
            foreach ($records as $record) {
                $size = strlen((string) $record['payload']) * 2;
                if ($chunk !== [] && (count($chunk) >= self::CHUNK || $bytes + $size > self::MAX_INSERT_BYTES)) {
                    VehicleRegistryEntry::insert($chunk);
                    [$chunk, $bytes] = [[], 0];
                }
                $chunk[] = $record;
                $bytes += $size;
            }
            if ($chunk !== []) {
                VehicleRegistryEntry::insert($chunk);
            }
        });

        $this->settings->forget('vehicles.registry_requested_at');
        $this->settings->set('emd.last_sync_at', $now->toIso8601String());

        return new IntakeResult($received, count($records), $received - count($records));
    }

    /**
     * The columns of one row, explicitly; null when it cannot be stored.
     *
     * @param  array<array-key, mixed>  $row
     * @return ?array<string, mixed>
     */
    private function record(array $row): ?array
    {
        $payload = json_encode($row, 0, self::MAX_DEPTH);
        $name = self::text($row['value'] ?? null, 255);
        if ($payload === false || strlen($payload) > self::MAX_ROW_BYTES || $name === null) {
            return null;
        }

        $record = [
            'emd_vehicle_id' => self::emdId($row['id'] ?? null),
            'name' => $name,
            'identifier' => substr(strtolower((string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $name)), 0, 255),
            'payload' => $payload,
        ];
        foreach (self::TEXTS as $key => [$column, $length]) {
            $record[$column] = self::text($row[$key] ?? null, $length);
        }
        $type = strtoupper($record['type'] ?? '');
        $record['service'] = match (true) {
            in_array($type, self::EMS_PHYSICIAN, true) => Vehicle::SERVICE_EMS_PHYSICIAN,
            in_array($type, self::EMS, true) => Vehicle::SERVICE_EMS,
            default => Vehicle::SERVICE_OTHER,
        };

        return $record;
    }

    private static function text(mixed $value, int $length): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $text = trim(str_replace("\0", '', (string) $value));

        return $text === '' ? null : mb_substr($text, 0, $length);
    }

    private static function emdId(mixed $id): ?int
    {
        if (is_string($id) && ctype_digit($id)) {
            $id = (int) $id;
        }

        return is_int($id) && $id >= 0 && $id <= 4294967295 ? $id : null;
    }
}
