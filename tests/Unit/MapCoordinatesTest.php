<?php

use App\Support\MapCoordinates;

test('GTA origin maps to the reference point', function () {
    expect(MapCoordinates::gtaToMap(0, 0))->toBe(['x' => 45.8, 'y' => 67.38]);
});

test('known calibration point converts correctly', function () {
    $r = MapCoordinates::gtaToMap(1115.5771, 2102.9556);
    expect($r['x'])->toEqualWithDelta(54.85, 0.05)->and($r['y'])->toEqualWithDelta(50.41, 0.05);
});

test('gtaToMap clamps to 0-100', function () {
    expect(MapCoordinates::gtaToMap(-100000, -100000))->toBe(['x' => 0.0, 'y' => 100.0])
        ->and(MapCoordinates::gtaToMap(100000, 100000))->toBe(['x' => 100.0, 'y' => 0.0]);
});

test('mapToGta is the inverse of gtaToMap', function () {
    $m = MapCoordinates::gtaToMap(500, 1000);
    $g = MapCoordinates::mapToGta($m['x'], $m['y']);
    expect($g['x'])->toEqualWithDelta(500, 1.0)->and($g['y'])->toEqualWithDelta(1000, 1.0);
});

test('mapToGta of the reference point is GTA origin', function () {
    $g = MapCoordinates::mapToGta(45.8, 67.38);
    expect($g['x'])->toEqualWithDelta(0, 0.01)->and($g['y'])->toEqualWithDelta(0, 0.01);
});

test('Y axis is inverted, X axis is not', function () {
    $o = MapCoordinates::gtaToMap(0, 0);
    expect(MapCoordinates::gtaToMap(0, 500)['y'])->toBeLessThan($o['y'])
        ->and(MapCoordinates::gtaToMap(500, 0)['x'])->toBeGreaterThan($o['x']);
});

test('results are rounded to two decimals', function () {
    $r = MapCoordinates::gtaToMap(100, 100);
    expect($r['x'])->toBe(round($r['x'], 2))->and($r['y'])->toBe(round($r['y'], 2));
});
