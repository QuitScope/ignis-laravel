<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Vehicles went out of service: switched inactive (form, bulk action) or a blocking defect was reported. Crew modules end
 * their open crews (eNOTF). Fired on the change itself, not on every save of an already inactive vehicle.
 */
class VehicleOutOfService implements ShouldDispatchAfterCommit
{
    /** @param list<int> $vehicleIds */
    public function __construct(public readonly array $vehicleIds) {}
}
