<?php

namespace App\Import;

use Carbon\Carbon;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Legacy date-times are wall time in config('ignis.import.legacy_timezone') (the legacy PHP zone;
 * the legacy MariaDB session time_zone should match it). Converts them to config('app.timezone').
 */
final class LegacyClock
{
    /** @throws InvalidArgumentException when the configured legacy timezone is not a valid timezone name */
    public static function legacyZone(): DateTimeZone
    {
        $name = (string) config('ignis.import.legacy_timezone', 'Europe/Berlin');
        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            throw new InvalidArgumentException("IGNIS_IMPORT_LEGACY_TIMEZONE '{$name}' is not a valid timezone");
        }
    }

    public static function toApp(?string $legacyWall, ?DateTimeZone $zone = null): ?string
    {
        $value = trim((string) $legacyWall);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        $zone ??= self::legacyZone();
        try {
            return Carbon::parse($value, $zone)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    public static function instant(object $row, string $column): ?string
    {
        $seconds = $row->{$column.'__unix'} ?? null;
        if ($seconds === null || (float) $seconds <= 0) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $seconds, (string) config('app.timezone'))->format('Y-m-d H:i:s');
    }

    public static function unusable(?string $legacyWall): bool
    {
        return trim((string) $legacyWall) !== '' && self::toApp($legacyWall) === null;
    }
}
