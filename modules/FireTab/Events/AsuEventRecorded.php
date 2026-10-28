<?php

namespace Modules\FireTab\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A new ASU event was stored; dispatched after the commit. */
final class AsuEventRecorded
{
    use Dispatchable;

    public function __construct(public readonly int $incidentId, public readonly int $eventId) {}
}
