<?php

use App\Dashboard\Widgets\VehicleKpiWidget;
use App\Dashboard\Widgets\VehiclesWidget;
use App\Models\Poi;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDefect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function vk_make(?string $status, bool $active = true): Vehicle
{
    return Vehicle::factory()->create(['fms_status' => $status, 'fms_status_at' => $status === null ? null : now(), 'is_active' => $active]);
}

it('computes ready, total, unknown and down among active vehicles', function (array $fixture, ?array $expected) {
    foreach ($fixture as [$status, $active]) {
        vk_make($status, $active);
    }

    expect((new VehicleKpiWidget)->data(new User))->toBe($expected);
})->with([
    'nothing reported means no tile' => [[[null, true], ['', true], ['1', false]], null],
    'no vehicles at all' => [[], null],
    'mix of statuses' => [
        [['1', true], ['2', true], ['3', true], ['6', true], [null, true], ['', true], ['1', false], ['6', false]],
        ['ready' => 2, 'total' => 4, 'down' => 1, 'unknown' => 2, 'blocked' => 0],
    ],
    'all down' => [[['6', true], ['6', true]], ['ready' => 0, 'total' => 2, 'down' => 2, 'unknown' => 0, 'blocked' => 0]],
]);

it('reports blocked separately: derived from open blocking defects, active vehicles only, status untouched', function () {
    $ready = vk_make('1');
    $other = vk_make('2');
    VehicleDefect::factory()->blocking()->create(['vehicle_id' => $ready->id]);
    VehicleDefect::factory()->blocking()->create(['vehicle_id' => $ready->id]);
    VehicleDefect::factory()->create(['vehicle_id' => $other->id]);
    VehicleDefect::factory()->blocking()->resolved()->create(['vehicle_id' => $other->id]);
    VehicleDefect::factory()->blocking()->create(['vehicle_id' => vk_make('6', false)->id]);

    expect((new VehicleKpiWidget)->data(new User))
        ->toBe(['ready' => 2, 'total' => 2, 'down' => 0, 'unknown' => 0, 'blocked' => 1]);
});

it('scales the tick bar to at most 40 ticks in proportion', function () {
    foreach (range(1, 60) as $i) {
        vk_make($i <= 15 ? '1' : '3');
    }
    $data = (new VehiclesWidget)->data(new User);

    expect($data)->toMatchArray(['total' => 60, 'ready' => 15, 'ticks' => 40, 'on' => 10]);
});

it('shows one tick per vehicle below 40', function () {
    vk_make('1');
    vk_make('3');
    vk_make('2');
    vk_make('6');
    vk_make(null);

    expect((new VehiclesWidget)->data(new User))->toMatchArray(['total' => 4, 'ready' => 2, 'ticks' => 4, 'on' => 2]);
});

it('lists the five most recently changed vehicles with state and station', function () {
    $poi = Poi::factory()->create(['name' => 'Wache 1']);
    foreach (range(1, 7) as $i) {
        Vehicle::factory()->create(['name' => "V$i", 'fms_status' => '1', 'fms_status_at' => now()->subMinutes(10 - $i), 'station_poi_id' => $i === 7 ? $poi->id : null]);
    }
    VehicleDefect::factory()->blocking()->create(['vehicle_id' => Vehicle::where('name', 'V6')->value('id')]);
    $rows = (new VehiclesWidget)->data(new User)['rows'];

    expect(array_column($rows, 'name'))->toBe(['V7', 'V6', 'V5', 'V4', 'V3'])
        ->and($rows[0])->toMatchArray(['state' => 'operable', 'station' => 'Wache 1', 'fms_label' => 'Einsatzbereit Funk'])
        ->and($rows[1])->toMatchArray(['state' => 'blocked', 'blocking' => 1, 'station' => null]);
});
