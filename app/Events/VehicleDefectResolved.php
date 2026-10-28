<?php

namespace App\Events;

use App\Models\VehicleDefect;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Queue\SerializesModels;

/** Dispatched once the resolution committed. Nothing flips `is_active` on it. */
class VehicleDefectResolved implements ShouldDispatchAfterCommit
{
    use SerializesModels;

    public function __construct(public readonly VehicleDefect $defect) {}
}
