<?php

use Carbon\CarbonImmutable;
use Modules\FireTab\Support\AsuState;

// Same fixture as tests/js/asuState.test.ts. Fixture times are ms; the adapter feeds the PHP port DateTimeInterface
// (as the report will), results come back in ms.
$fixture = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/asu-cases.json'), true);

it('derives the same state as the TS port', function (array $c) {
    $events = array_map(fn (array $e) => ['at' => CarbonImmutable::createFromTimestampMs($e['at'])] + $e, $c['events']);
    $state = AsuState::derive($c['trupp'], $events, CarbonImmutable::createFromTimestampMs($c['now']), $c['config'] ?: []);

    foreach ($c['expect'] as $path => $want) {
        $got = data_get($state, $path);
        if (is_int($want) || is_float($want)) {
            expect(abs($got - $want))->toBeLessThanOrEqual(1e-6, $path);
        } else {
            expect($got)->toBe($want, $path);
        }
    }
})->with(array_combine(array_column($fixture['cases'], 'name'), array_map(fn (array $c) => [$c], $fixture['cases'])));
