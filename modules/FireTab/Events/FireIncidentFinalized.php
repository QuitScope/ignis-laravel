<?php

namespace Modules\FireTab\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Declared here, dispatched and handled by the finalize action. */
final class FireIncidentFinalized
{
    use Dispatchable;

    public function __construct(public readonly int $incidentId) {}
}
