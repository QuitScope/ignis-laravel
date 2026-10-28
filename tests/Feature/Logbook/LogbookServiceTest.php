<?php

use App\Models\Poi;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Logbook\LogbookService;
use Modules\Logbook\Models\LogbookEntry;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function sv_data(array $over = []): array
{
    return $over + ['trip_date' => '2026-10-20', 'departed_at' => '08:30', 'arrived_at' => '09:15', 'distance_km' => 5, 'reason' => null, 'trip_type' => 'einsatz'];
}

it('records an entry from enotf and firetab without an audit entry when there is no account', function (string $source) {
    $poi = Poi::factory()->create(['name' => 'Wache', 'city' => '']);
    $v = Vehicle::factory()->create(['identifier' => 'RTW-9', 'station_poi_id' => $poi->id]);

    $e = app(LogbookService::class)->record(sv_data(), $v, 'Erika Muster', $source, null);

    expect($e->exists)->toBeTrue()->and($e->source)->toBe($source)->and($e->created_by)->toBeNull()
        ->and($e->vehicle_identifier)->toBe('RTW-9')->and($e->station_name)->toBe('Wache')->and($e->driver_name)->toBe('Erika Muster');
    expect(Activity::count())->toBe(0);
})->with(['enotf', 'firetab']);

it('writes the audit entry and created_by only with an account', function () {
    $user = User::factory()->create();

    $e = app(LogbookService::class)->record(sv_data(), Vehicle::factory()->create(), 'X', 'admin', $user);

    expect($e->created_by)->toBe($user->id);
    $a = Activity::query()->where('log_name', 'Fahrtenbuch')->sole();
    expect($a->description)->toBe("Fahrtenbuch-Eintrag erstellt [ID: {$e->id}]")->and($a->causer_id)->toBe($user->id);
});

it('rejects an unknown source and invalid data without writing', function (array $data, string $driver, string $source, string $field) {
    $v = Vehicle::factory()->create();

    try {
        app(LogbookService::class)->record($data, $v, $driver, $source, null);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }

    expect(LogbookEntry::count())->toBe(0);
})->with([
    'source' => [sv_data(), 'X', 'web', 'source'],
    'empty source' => [sv_data(), 'X', '', 'source'],
    'driver' => [sv_data(), '  ', 'enotf', 'driver_name'],
    'type' => [sv_data(['trip_type' => 'x']), 'X', 'enotf', 'trip_type'],
    'km' => [sv_data(['distance_km' => -2]), 'X', 'enotf', 'distance_km'],
    'time' => [sv_data(['departed_at' => '99:99']), 'X', 'enotf', 'departed_at'],
]);

it('ignores extra keys in the data (source, created_by, vehicle texts)', function () {
    $v = Vehicle::factory()->create(['identifier' => 'RTW-1']);

    $e = app(LogbookService::class)->record(sv_data(['source' => 'enotf', 'created_by' => 5, 'vehicle_identifier' => 'FAKE']), $v, 'X', 'firetab', null);

    expect($e->source)->toBe('firetab')->and($e->created_by)->toBeNull()->and($e->vehicle_identifier)->toBe('RTW-1');
});

it('refuses to write while the module is switched off', function () {
    app(Settings::class)->set('modules.logbook.enabled', false);

    expect(fn () => app(LogbookService::class)->record(sv_data(), Vehicle::factory()->create(), 'X', 'enotf', null))->toThrow(LogicException::class);
    expect(LogbookEntry::count())->toBe(0);
});
