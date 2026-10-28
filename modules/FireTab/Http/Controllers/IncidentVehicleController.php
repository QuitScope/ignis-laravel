<?php

namespace Modules\FireTab\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Support\SqlLike;
use App\Vehicles\CrewSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\FireTab\Http\Requests\VehicleRequest;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentVehicle;
use Modules\FireTab\Support\FireLog;

class IncidentVehicleController extends Controller
{
    /** GET /firetab/vehicles/search?q=: active vehicles by identifier or name, at most 20 (never the whole fleet). */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('create', FireIncident::class);
        $q = $request->query('q', '');
        abort_unless(is_string($q), 422);
        $q = trim($q);
        $hits = [];
        if ($q !== '' && mb_strlen($q) <= 50) {
            $query = Vehicle::query()->active()->select(['id', 'identifier', 'name']);
            SqlLike::anyOf($query, ['identifier', 'name'], $q);
            $hits = $query->orderBy('sort_order')->orderBy('identifier')->limit(20)->get()
                ->map(fn (Vehicle $v) => ['id' => $v->id, 'identifier' => $v->identifier, 'name' => $v->name])->all();
        }

        return response()->json($hits);
    }

    public function store(VehicleRequest $request, FireIncident $incident): RedirectResponse
    {
        $d = $request->validated();
        $taken = ValidationException::withMessages(['vehicle_id' => __('firetab.vehicles.taken')]);

        try {
            DB::transaction(function () use ($d, $incident, $taken) {
                if (isset($d['vehicle_id'])) {
                    if ($incident->vehicles()->where('vehicle_id', $d['vehicle_id'])->exists()) {
                        throw $taken;
                    }
                    $vehicle = Vehicle::query()->findOrFail($d['vehicle_id']);
                    FireIncidentController::attach($incident, $vehicle);
                    $label = $vehicle->name;
                } else {
                    (new FireIncidentVehicle)->forceFill([
                        'fire_incident_id' => $incident->id, 'name' => $d['radio_name'], 'radio_name' => $d['radio_name'], 'is_external' => true,
                    ])->save();
                    $label = $d['radio_name'];
                }
                FireLog::record($incident, 'vehicle_added', $label);
            });
        } catch (UniqueConstraintViolationException) {
            throw $taken;
        }

        return back();
    }

    public function destroy(Request $request, FireIncident $incident, FireIncidentVehicle $vehicle): RedirectResponse
    {
        $this->authorize('update', $incident);
        if ($vehicle->vehicle_id !== null && $vehicle->vehicle_id === (CrewSession::for('firetab')->current()['vehicle_id'] ?? null)) {
            throw ValidationException::withMessages(['vehicle' => __('firetab.vehicles.own')]);
        }

        DB::transaction(function () use ($incident, $vehicle) {
            $label = $vehicle->radio_name ?? $vehicle->name;
            $vehicle->delete();
            FireLog::record($incident, 'vehicle_removed', $label);
        });

        return back();
    }
}
