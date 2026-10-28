<?php

namespace Modules\FireTab\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Any write to a protocol; dispatched after the commit (FireLog::record). Listeners come with later tasks. */
final class FireIncidentUpdated
{
    use Dispatchable;

    public function __construct(public readonly int $incidentId) {}
}
