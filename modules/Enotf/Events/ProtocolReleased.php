<?php

namespace Modules\Enotf\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched by ReleaseController only after the commit (DB::afterCommit): exactly once per release. */
final class ProtocolReleased
{
    use Dispatchable;

    public function __construct(public readonly int $protocolId, public readonly int $userId) {}
}
