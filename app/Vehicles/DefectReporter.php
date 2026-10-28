<?php

namespace App\Vehicles;

use App\Events\VehicleDefectReported;
use App\Events\VehicleOutOfService;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDefect;
use App\Models\VehicleDefectEvent;
use Illuminate\Support\Facades\DB;

/**
 * Records a defect report (legacy src/Vehicles/DefectReporter.php): the defect, its `created` event and
 * VehicleDefectReported after the commit. Unlike the legacy one it never switches the vehicle off: a blocking
 * defect only makes it inoperable, `is_active` stays the admin's.
 *
 * Two callers: the report form (VehicleDefectController) and the crew path without an account. The
 * caller authorizes; this class trusts its input apart from the category whitelist.
 */
final class DefectReporter
{
    /**
     * @param  array{title: string, description?: ?string, category?: mixed, is_operable?: bool}  $data
     * @param  ?string  $reporterName  shown for crew without an account; ignored when $reporter is given
     */
    public function report(Vehicle $vehicle, array $data, ?User $reporter, ?string $reporterName = null): VehicleDefect
    {
        $category = in_array($data['category'] ?? null, VehicleDefect::CATEGORIES, true) ? $data['category'] : 'other';
        $crewName = $reporter === null && $reporterName !== null && trim($reporterName) !== '' ? mb_substr(trim($reporterName), 0, 200) : null;

        return DB::transaction(function () use ($vehicle, $data, $reporter, $category, $crewName) {
            $defect = VehicleDefect::create([
                'vehicle_id' => $vehicle->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'category' => $category,
                'is_operable' => $data['is_operable'] ?? true,
                'status' => VehicleDefect::OPEN,
                'reported_by' => $reporter?->id,
                'reporter_name' => $crewName,
            ]);
            self::log($defect, $reporter, 'created',
                'Defekt gemeldet: '.$defect->title.($crewName === null ? '' : ' (Gemeldet durch: '.$crewName.')'));

            event(new VehicleDefectReported($defect));
            if (! $defect->is_operable) {
                event(new VehicleOutOfService([$vehicle->id]));
            }

            return $defect;
        });
    }

    public static function log(VehicleDefect $defect, ?User $user, string $action, ?string $details = null): void
    {
        VehicleDefectEvent::create(['vehicle_defect_id' => $defect->id, 'user_id' => $user?->id, 'action' => $action, 'details' => $details]);
    }
}
