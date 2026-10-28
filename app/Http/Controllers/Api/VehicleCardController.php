<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleDefect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Vehicle hover card (legacy Api\VehicleCardController): a login is enough, as before. A missing or malformed
 * id answers exactly like the personnel cards' 404.
 */
class VehicleCardController extends Controller
{
    public function show(string $id): JsonResponse
    {
        $vehicle = preg_match('/^\d{1,18}$/D', $id) === 1
            ? Vehicle::query()->withBlockingDefectsCount()->withCount(['defects as info_defects_count' => fn (Builder $q) => $q
                ->where('is_operable', true)->where('status', '!=', VehicleDefect::RESOLVED)])->find((int) $id)
            : null;
        if ($vehicle === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json([
            'id' => $vehicle->id,
            'name' => $vehicle->name,
            'identifier' => $vehicle->identifier,
            'type' => $vehicle->type,
            'service_label' => trans('vehicles.service.'.$vehicle->service),
            'license_plate' => $vehicle->license_plate,
            'state' => $vehicle->operabilityState(),
            'blocking_defects' => $vehicle->blocking_defects_count,
            'info_defects' => (int) $vehicle->getAttribute('info_defects_count'),
        ])->header('Cache-Control', 'private, max-age=60');
    }
}
