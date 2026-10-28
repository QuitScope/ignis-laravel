<?php

use App\Import\LegacyClock;

it('converts legacy wall time to the app timezone', function (string $zone, string $in, ?string $out) {
    config(['ignis.import.legacy_timezone' => $zone, 'app.timezone' => 'UTC']);

    expect(LegacyClock::toApp($in))->toBe($out);
})->with([
    'winter' => ['Europe/Berlin', '2026-01-15 12:00:00', '2026-01-15 11:00:00'],
    'summer' => ['Europe/Berlin', '2026-07-15 12:00:00', '2026-07-15 10:00:00'],
    'utc legacy' => ['UTC', '2026-07-15 12:00:00', '2026-07-15 12:00:00'],
    'zero date' => ['Europe/Berlin', '0000-00-00 00:00:00', null],
    'empty' => ['Europe/Berlin', '', null],
    'garbage' => ['Europe/Berlin', 'not a date', null],
]);

it('returns null for null and survives DST gap and overlap', function () {
    config(['ignis.import.legacy_timezone' => 'Europe/Berlin']);

    expect(LegacyClock::toApp(null))->toBeNull()
        ->and(LegacyClock::toApp('2026-03-29 02:30:00'))->toBeString()
        ->and(LegacyClock::toApp('2026-10-25 02:30:00'))->toBeString();
});

it('defaults the legacy zone to Europe/Berlin', function () {
    expect(config('ignis.import.legacy_timezone'))->toBe('Europe/Berlin');
});

it('throws a clear error for an invalid configured zone instead of returning null', function () {
    config(['ignis.import.legacy_timezone' => 'Europa/Berlin']);

    expect(fn () => LegacyClock::toApp('2026-01-15 12:00:00'))
        ->toThrow(InvalidArgumentException::class, "IGNIS_IMPORT_LEGACY_TIMEZONE 'Europa/Berlin' is not a valid timezone");
});

it('still returns null for garbage dates under a valid zone', function () {
    config(['ignis.import.legacy_timezone' => 'Europe/Berlin']);

    expect(LegacyClock::toApp('31.31.2026 abc'))->toBeNull();
});
