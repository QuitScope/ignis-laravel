<?php

namespace App\Vehicles;

use App\Models\Vehicle;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/** Writes a vehicle's FMS status. Shared by FireTab (status buttons) and the dispatch sync. */
final class FmsStatus
{
    public const CODES = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'N', '#', 'C'];

    /** @param string $source manual, no_dispatch (EMD without mission), dispatch (EMD mission status, never queued back), incident */
    public static function set(Vehicle $vehicle, string $code, string $source, ?CarbonInterface $at = null): void
    {
        if (! in_array($code, self::CODES, true)) {
            throw new InvalidArgumentException("Invalid FMS status [$code].");
        }

        // An older dispatch status (resent until acknowledged, or a fallback after it) never overwrites a newer one.
        if ($at !== null && $vehicle->fms_status_at !== null && $at->lt($vehicle->fms_status_at)) {
            return;
        }
        $vehicle->forceFill(['fms_status' => $code, 'fms_status_at' => $at ?? now(), 'fms_status_source' => $source])->save();
    }
}
