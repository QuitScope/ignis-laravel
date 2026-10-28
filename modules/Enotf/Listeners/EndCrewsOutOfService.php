<?php

namespace Modules\Enotf\Listeners;

use App\Events\VehicleOutOfService;
use Modules\Enotf\Support\CrewService;

/** A vehicle out of service ends its open crews; their devices see `crew: null` at the next status poll. */
class EndCrewsOutOfService
{
    public function __construct(private readonly CrewService $crews) {}

    public function handle(VehicleOutOfService $event): void
    {
        $this->crews->closeVehicles($event->vehicleIds, 'vehicle_out_of_service');
    }
}
