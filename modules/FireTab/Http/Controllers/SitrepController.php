<?php

namespace Modules\FireTab\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Modules\FireTab\Enums\SitrepSource;
use Modules\FireTab\Http\Requests\SitrepRequest;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentVehicle;
use Modules\FireTab\Models\FireSitrep;
use Modules\FireTab\Support\FireLog;
use Modules\FireTab\Support\OrganizationTime;

class SitrepController extends Controller
{
    public function store(SitrepRequest $request, FireIncident $incident): RedirectResponse
    {
        $d = $request->validated();
        $reporter = FireIncidentVehicle::query()->where('fire_incident_id', $incident->id)->findOrFail($d['incident_vehicle_id']);

        DB::transaction(function () use ($d, $incident, $reporter) {
            (new FireSitrep)->forceFill([
                'fire_incident_id' => $incident->id,
                'reported_at' => OrganizationTime::utc($d['date'], $d['time']),
                'text' => $d['text'],
                'reporter_name' => $reporter->radio_name ?? $reporter->name,
                'source' => SitrepSource::Local,
                'synced_at' => null,
            ])->save();
            FireLog::record($incident, 'sitrep_added');
        });

        return back();
    }
}
