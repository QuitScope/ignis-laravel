<?php

namespace Modules\FireTab\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Date and time inputs are wall time in the organisation zone (config ignis.timezone); storage is UTC. */
final class OrganizationTime
{
    public static function utc(string $date, string $time): string
    {
        return CarbonImmutable::parse("$date $time", (string) config('ignis.timezone'))->utc()->format('Y-m-d H:i:s');
    }

    public static function local(?CarbonInterface $at): ?CarbonImmutable
    {
        return $at === null ? null : CarbonImmutable::instance($at)->setTimezone((string) config('ignis.timezone'));
    }

    public static function display(?CarbonInterface $at): ?string
    {
        return self::local($at)?->format('d.m.Y H:i');
    }
}
