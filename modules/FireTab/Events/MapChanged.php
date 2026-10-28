<?php

namespace Modules\FireTab\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A marker or zone changed; dispatched after the commit. */
final class MapChanged
{
    use Dispatchable;

    public function __construct(public readonly int $incidentId) {}
}
