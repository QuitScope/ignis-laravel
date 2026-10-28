<?php

use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentLog;
use Modules\FireTab\Support\DispatchIncidents;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('creates the incident from a mixed dispatch with only the fire vehicle, placeholders and a system log', function () {
    Vehicle::factory()->create(['name' => 'NEF 1', 'service' => Vehicle::SERVICE_EMS_PHYSICIAN]);
    Vehicle::factory()->create(['name' => 'RTW 1', 'service' => Vehicle::SERVICE_EMS]);
    $lhf = Vehicle::factory()->create(['name' => 'LHF 1', 'service' => Vehicle::SERVICE_FIRE]);

    $incident = DispatchIncidents::ensure(['number' => 4711, 'vehicles' => ['NEF 1', 'RTW 1', 'LHF 1'], 'dispatch_issue' => 'Brand Keller']);

    expect($incident->number)->toBe('4711')
        ->and($incident->vehicles()->pluck('vehicle_id')->all())->toBe([$lhf->id])
        ->and($incident->location)->toBe(DispatchIncidents::PLACEHOLDER)->and($incident->keyword)->toBe(DispatchIncidents::PLACEHOLDER)
        ->and($incident->notes)->toStartWith('EINSATZMELDUNG: Brand Keller')
        ->and($incident->started_at)->not->toBeNull();
    $log = FireIncidentLog::sole();
    expect($log->getAttribute('action'))->toBe('created')->and($log->getAttribute('user_id'))->toBeNull();

    expect(DispatchIncidents::ensure(['number' => 4712, 'vehicles' => ['NEF 1', 'RTW 1']]))->toBeNull()
        ->and(FireIncident::count())->toBe(1);
});

it('is idempotent for a known number and only appends new fire vehicles', function () {
    $a = Vehicle::factory()->create(['name' => 'HLF 1', 'service' => Vehicle::SERVICE_FIRE]);
    $b = Vehicle::factory()->create(['name' => 'DLK 1', 'service' => Vehicle::SERVICE_FIRE]);
    $first = DispatchIncidents::ensure(['number' => '9', 'vehicles' => ['HLF 1'], 'postal' => '123', 'dispatch_code' => 'B3']);

    $again = DispatchIncidents::ensure(['number' => '9', 'vehicles' => ['HLF 1', 'DLK 1']]);
    DispatchIncidents::ensure(['number' => '9', 'vehicles' => ['DLK 1']]);

    expect($again->id)->toBe($first->id)->and(FireIncident::count())->toBe(1)
        ->and($first->vehicles()->orderBy('vehicle_id')->pluck('vehicle_id')->all())->toBe([$a->id, $b->id])
        ->and($first->fresh()->location)->toBe('123')
        ->and(FireIncidentLog::where('action', 'vehicle_added')->count())->toBe(1);
});
