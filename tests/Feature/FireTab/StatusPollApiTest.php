<?php

use App\Auth\ApiKey;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FireTab\Models\FireStatusQueue;
use Modules\FireTab\Support\StatusQueue;

uses(RefreshDatabase::class);

it('needs the api key, works without session or CSRF and hands every row out once in the legacy shape', function () {
    config(['ignis.timezone' => 'Europe/Berlin']);
    $key = app(ApiKey::class)->current();
    $vehicle = Vehicle::factory()->create(['name' => 'LHF 1']);
    $this->travelTo('2026-07-01 12:30:00');
    StatusQueue::push($vehicle, '3', 'E-7');

    $this->postJson('/api/emd/status-poll')->assertForbidden();

    $this->post('/api/emd/status-poll', [], ['X-API-Key' => $key])->assertOk()
        ->assertExactJson(['success' => true, 'status_changes' => [
            ['vehicle_name' => 'LHF 1', 'status' => '3', 'incident_number' => 'E-7', 'timestamp' => '01.07.2026 14:30'],
        ]])->assertCookieMissing(config('session.cookie'));

    expect(FireStatusQueue::sole()->delivered_at)->not->toBeNull();
    $this->post('/api/emd/status-poll', [], ['X-API-Key' => $key])->assertExactJson(['success' => true, 'status_changes' => []]);
});
