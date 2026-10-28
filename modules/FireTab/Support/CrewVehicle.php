<?php

namespace Modules\FireTab\Support;

use App\Vehicles\CrewSession;

/** The vehicle of the current FireTab crew session (Fahrzeugsitzung); bind another implementation to override. */
class CrewVehicle
{
    public function id(): ?int
    {
        return CrewSession::for('firetab')->current()['vehicle_id'] ?? null;
    }
}
