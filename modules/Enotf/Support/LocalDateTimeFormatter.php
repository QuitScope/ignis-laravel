<?php

namespace Modules\Enotf\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Display time in the organisation zone (storage is UTC). */
final class LocalDateTimeFormatter
{
    public static function at(?CarbonInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->setTimezone((string) config('ignis.timezone'))->format('d.m.Y H:i');
    }
}
