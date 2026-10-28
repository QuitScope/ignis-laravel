<?php

namespace Modules\FireTab\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** The device time (server clock per offset) counts if received_at − 15 min ≤ occurred_at ≤ received_at + 5 s, else received_at. */
final class AsuClock
{
    public const PAST_SECONDS = 15 * 60;

    public const FUTURE_SECONDS = 5;

    public static function resolve(?CarbonInterface $occurred, CarbonInterface $received): CarbonImmutable
    {
        $received = CarbonImmutable::instance($received);
        if ($occurred === null
            || $occurred->lt($received->subSeconds(self::PAST_SECONDS))
            || $occurred->gt($received->addSeconds(self::FUTURE_SECONDS))) {
            return $received;
        }

        return CarbonImmutable::instance($occurred);
    }
}
