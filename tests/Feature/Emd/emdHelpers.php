<?php

use App\Auth\ApiKey;
use App\Models\Vehicle;
use Illuminate\Testing\TestResponse;

/** The ef_bridge heartbeat (server/emd_sync.lua BuildHeartbeatPayload) with the real key, as B posts it: api/emd/sync.php, key in the body. */
function emd_fixture(string $name = 'heartbeat_v2'): array
{
    $body = json_decode((string) file_get_contents(base_path("tests/Fixtures/emd/$name.json")), true, 32, JSON_THROW_ON_ERROR);
    $body['intraRP_API_Key'] = app(ApiKey::class)->current();

    return $body;
}

function emd_sync(array $body, string $url = '/api/emd/sync.php'): TestResponse
{
    return test()->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_USER_AGENT' => 'ef_bridge'], json_encode($body));
}

function emd_vehicles(): array
{
    return [
        'rtw' => Vehicle::factory()->create(['name' => 'RTW 1/83-1', 'identifier' => 'rtw-1-83-1', 'service' => Vehicle::SERVICE_EMS]),
        'nef' => Vehicle::factory()->create(['name' => 'NEF 1/82-1', 'identifier' => 'nef-1-82-1', 'service' => Vehicle::SERVICE_EMS_PHYSICIAN, 'type' => 'NEF']),
        'lhf' => Vehicle::factory()->create(['name' => 'LHF 1/44-1', 'identifier' => 'lhf-1-44-1', 'service' => Vehicle::SERVICE_FIRE, 'type' => 'LHF']),
    ];
}
