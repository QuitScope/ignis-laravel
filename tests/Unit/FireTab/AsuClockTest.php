<?php

use Carbon\CarbonImmutable;
use Modules\FireTab\Support\AsuClock;

it('takes the device time only inside [received - 15 min, received + 5 s]', function (int $offsetSeconds, bool $accepted) {
    $received = CarbonImmutable::parse('2026-10-22 12:00:00');
    $occurred = $received->addSeconds($offsetSeconds);

    expect(AsuClock::resolve($occurred, $received)->equalTo($accepted ? $occurred : $received))->toBeTrue();
})->with([
    'exactly -15 min' => [-900, true],
    'past beyond window' => [-901, false],
    'far past' => [-86400, false],
    'exactly +5 s' => [5, true],
    'future beyond window' => [6, false],
    'far future' => [3600, false],
    'two minutes offline' => [-120, true],
]);

it('falls back to the received time without a device time', function () {
    $received = CarbonImmutable::parse('2026-10-22 12:00:00');

    expect(AsuClock::resolve(null, $received)->equalTo($received))->toBeTrue();
});
