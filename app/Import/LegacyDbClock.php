<?php

namespace App\Import;

use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Wall-time clock of the legacy database, only for DATETIME columns the legacy app
 * filled with `NOW()` (`resolved_at`, `processed_at`, `status_updated_at` of source incident): those hold the wall time
 * of the server's time_zone at writing. TIMESTAMP columns are instants and do not use this class
 * (LegacyReader::tableWithInstants()).
 *
 * Zone: config('ignis.import.legacy_db_timezone') (IGNIS_IMPORT_LEGACY_DB_TIMEZONE) when set. Otherwise the zone the
 * legacy server runs in now (session, else @@system_time_zone; a named zone keeps DST right, an abbreviation or offset
 * cannot), plus a report warning: this is only right when the server ran in that zone when it wrote the values, and
 * wrong for a dump restored on another server.
 */
final class LegacyDbClock
{
    private function __construct(public readonly DateTimeZone $zone, public readonly string $source) {}

    /**
     * The configured zone, or null when it is measured.
     *
     * @throws InvalidArgumentException when the configured name is not a valid timezone
     */
    public static function configuredZone(): ?DateTimeZone
    {
        $name = trim((string) config('ignis.import.legacy_db_timezone'));
        if ($name === '') {
            return null;
        }
        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            throw new InvalidArgumentException("IGNIS_IMPORT_LEGACY_DB_TIMEZONE '{$name}' is not a valid timezone");
        }
    }

    public static function for(LegacyReader $legacy, ImportReport $report): self
    {
        $configured = self::configuredZone();
        if ($configured !== null) {
            return new self($configured, 'IGNIS_IMPORT_LEGACY_DB_TIMEZONE');
        }
        $row = $legacy->select('SELECT @@session.time_zone AS s, @@global.time_zone AS g, @@system_time_zone AS sys, '
            .'TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS diff')[0];
        $seconds = (int) (round((int) $row->diff / 900) * 900);
        $offset = sprintf('%s%02d:%02d', $seconds < 0 ? '-' : '+', intdiv(abs($seconds), 3600), intdiv(abs($seconds) % 3600, 60));
        $name = (string) ($row->s === 'SYSTEM' ? $row->sys : $row->s);
        try {
            $zone = new DateTimeZone($name);
        } catch (Throwable) {
            $zone = new DateTimeZone($offset);
        }
        $report->warnOnce("IGNIS_IMPORT_LEGACY_DB_TIMEZONE is not set: NOW()-written DATETIME columns are read as {$zone->getName()} "
            ."(@@global.time_zone = {$row->g}, @@system_time_zone = {$row->sys}, offset now {$offset}). This assumes the legacy server ran in this "
            .'zone when it wrote them; when importing from a restored dump, set IGNIS_IMPORT_LEGACY_DB_TIMEZONE to the original server\'s zone.');

        return new self($zone, 'legacy server zone');
    }

    public function toApp(?string $dbWall): ?string
    {
        return LegacyClock::toApp($dbWall, $this->zone);
    }

    public function describe(): string
    {
        return "NOW()-written DATETIME columns read in {$this->zone->getName()} ({$this->source}); TIMESTAMP columns converted per value; "
            .'PHP-written times use '.LegacyClock::legacyZone()->getName();
    }
}
