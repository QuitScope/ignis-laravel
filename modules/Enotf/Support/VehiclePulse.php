<?php

namespace Modules\Enotf\Support;

use App\Realtime\Realtime;

/**
 * "Look at /enotf/status again" for the devices of these vehicles (channel enotf.vehicle.{id}, topic `status`).
 * The client's ETag poll does the rest (crew, lock, protocol revisions, share requests).
 */
final class VehiclePulse
{
    public static function send(?int ...$vehicleIds): void
    {
        foreach (array_unique(array_filter($vehicleIds)) as $id) {
            Realtime::pulse('enotf.vehicle.'.$id, 'status');
        }
    }
}
