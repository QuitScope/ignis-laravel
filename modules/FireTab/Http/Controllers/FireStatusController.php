<?php

namespace Modules\FireTab\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Vehicles\CrewSession;
use App\Vehicles\FmsStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentVehicle;
use Modules\FireTab\Support\FireLog;
use Modules\FireTab\Support\OrganizationTime;
use Modules\FireTab\Support\StatusQueue;

/** FMS status buttons S0-S6 of the crew's vehicle (also without an active incident). Routes carry `firetab.crew`. */
class FireStatusController extends Controller
{
    public const BUTTONS = ['0', '1', '2', '3', '4', '5', '6'];

    public function show(): Response
    {
        $vehicle = $this->vehicle();
        $incident = $this->activeIncident($vehicle);

        return Inertia::render('FireTab/Status', [
            'vehicle' => [
                'id' => $vehicle->id, 'identifier' => $vehicle->identifier, 'name' => $vehicle->name, 'status' => $vehicle->fms_status,
                'label' => Vehicle::fmsLabel($vehicle->fms_status), 'at' => OrganizationTime::display($vehicle->fms_status_at),
            ],
            'incident' => $incident === null ? null : ['id' => $incident->id, 'number' => $incident->number, 'keyword' => $incident->keyword, 'location' => $incident->location],
            'buttons' => array_map(fn (string $c) => ['code' => $c, 'label' => Vehicle::fmsLabel($c)], self::BUTTONS),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $code = $request->validate(['status' => ['required', 'string', Rule::in(self::BUTTONS)]])['status'];
        $vehicle = $this->vehicle();

        DB::transaction(function () use ($vehicle, $code) {
            $incident = $this->activeIncident($vehicle);
            FmsStatus::set($vehicle, $code, 'incident');
            if ($incident !== null) {
                FireIncidentVehicle::query()->where('fire_incident_id', $incident->id)->where('vehicle_id', $vehicle->id)
                    ->update(['fms_status' => $code, 'fms_status_at' => now()]);
                FireLog::record($incident, 'fms_status', $code.' ('.Vehicle::fmsLabel($code).')');
            }
            StatusQueue::push($vehicle, $code, $incident?->number);
        });

        return back();
    }

    private function vehicle(): Vehicle
    {
        return Vehicle::query()->findOrFail(CrewSession::for('firetab')->current()['vehicle_id'] ?? 0);
    }

    private function activeIncident(Vehicle $vehicle): ?FireIncident
    {
        return FireIncident::query()->whereNull('finalized_at')->whereNull('archived_at')
            ->whereHas('vehicles', fn ($q) => $q->where('vehicle_id', $vehicle->id))
            ->orderByDesc('started_at')->orderByDesc('id')->first();
    }
}
