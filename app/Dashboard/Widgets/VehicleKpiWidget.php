<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Models\User;
use App\Models\Vehicle;

/**
 * "Fahrzeuge einsatzbereit" (legacy Overview::vehicles): among ACTIVE vehicles those that reported an FMS status are
 * the base (`total`); ready = status 1/2, down = status 6, unknown = active without status. No status at all: no tile.
 * `blocked` is separate: active vehicles with an open blocking defect, derived, not an FMS status.
 */
final class VehicleKpiWidget implements Widget
{
    public function key(): string
    {
        return 'vehicle_kpi';
    }

    public function area(): string
    {
        return 'kpi';
    }

    public function order(): int
    {
        return 20;
    }

    public function allowed(User $user): bool
    {
        return $user->can('vehicle.view');
    }

    public function data(User $user): ?array
    {
        $row = Vehicle::active()->selectRaw(
            "COALESCE(SUM(CASE WHEN fms_status IS NOT NULL AND fms_status <> '' THEN 1 ELSE 0 END), 0) AS total,"
            ."COALESCE(SUM(CASE WHEN fms_status IN ('1', '2') THEN 1 ELSE 0 END), 0) AS ready,"
            ."COALESCE(SUM(CASE WHEN fms_status = '6' THEN 1 ELSE 0 END), 0) AS down,"
            ."COALESCE(SUM(CASE WHEN fms_status IS NULL OR fms_status = '' THEN 1 ELSE 0 END), 0) AS unknown"
        )->toBase()->first();
        if ((int) $row->total === 0) {
            return null;
        }

        return [
            'ready' => (int) $row->ready,
            'total' => (int) $row->total,
            'down' => (int) $row->down,
            'unknown' => (int) $row->unknown,
            'blocked' => Vehicle::active()->whereHas('blockingDefects')->count(),
        ];
    }
}
