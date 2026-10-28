<?php

namespace Modules\FireTab\Support;

use App\Vehicles\CrewSession;
use Illuminate\Support\Facades\DB;
use Modules\FireTab\Events\FireIncidentUpdated;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentLog;

/** Protocol log. Account, personnel record and vehicle come from the session, never from request input. */
final class FireLog
{
    /** @param bool $system no account and no crew (dispatch sync); vehicle only when passed */
    public static function record(FireIncident $incident, string $action, ?string $description = null, ?int $vehicleId = null, ?int $personnelId = null, bool $system = false): void
    {
        $crew = $system ? null : CrewSession::for('firetab')->current();

        (new FireIncidentLog)->forceFill([
            'fire_incident_id' => $incident->id,
            'action' => $action,
            'description' => $description,
            'vehicle_id' => $vehicleId ?? $crew['vehicle_id'] ?? null,
            'personnel_id' => $personnelId ?? $crew['personnel_id'] ?? null,
            'user_id' => $system ? null : auth()->id(),
        ])->save();

        DB::afterCommit(fn () => FireIncidentUpdated::dispatch($incident->id));
    }
}
