<?php

use App\Auth\ApiKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Enotf\Enums\ProtocolOrigin;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentVehicle;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-10-23 14:15', (string) config('ignis.timezone'))));

require_once __DIR__.'/emdHelpers.php';

function st_body(array $statuses, array $extra = []): array
{
    return ['intraRP_API_Key' => app(ApiKey::class)->current(), 'protocol_version' => 2, 'status_updates' => ['statuses' => $statuses]] + $extra;
}

it('fire status lands on the incident vehicle; released protocols, finalized incidents and unknown vehicles stay untouched but are final', function () {
    ['rtw' => $rtw, 'lhf' => $lhf] = emd_vehicles();
    emd_sync(['intraRP_API_Key' => app(ApiKey::class)->current(), 'protocol_version' => 2,
        'dispatch_data' => ['vehicles' => [['value' => 'LHF 1/44-1', 'dispatch' => 900]]]])->assertOk();
    $released = (new EnotfProtocol)->forceFill(['enr' => '901', 'origin' => ProtocolOrigin::Dispatch, 'status' => ProtocolStatus::Released, 'vehicle_transport_id' => $rtw->id]);
    $released->save();

    emd_sync(st_body([
        ['id' => 1, 'mission_number' => 900, 'sender' => 'LHF 1/44-1', 'status' => '3', 'timestamp' => '23.10.2026 14:10'],
        ['id' => 2, 'mission_number' => 901, 'sender' => 'RTW 1/83-1', 'status' => '4', 'timestamp' => '23.10.2026 14:11'],
        ['id' => 3, 'mission_number' => 999, 'sender' => 'Unbekannt 1', 'status' => '3', 'timestamp' => '23.10.2026 14:11'],
    ]))->assertOk()->assertJsonPath('status_ack.successful_ids', [1, 2, 3]);

    expect(FireIncidentVehicle::query()->where('vehicle_id', $lhf->id)->value('fms_status'))->toBe('3')
        ->and($lhf->fresh()->fms_status_source)->toBe('dispatch')
        ->and($released->fresh()->data)->toBeNull()->and($released->fresh()->revision)->toBe(1);

    FireIncident::query()->update(['finalized_at' => now()]);
    emd_sync(st_body([['id' => 4, 'mission_number' => 900, 'sender' => 'LHF 1/44-1', 'status' => '4', 'timestamp' => '23.10.2026 14:20']]))
        ->assertJsonPath('status_ack.successful_ids', [4]);
    expect(FireIncidentVehicle::query()->where('vehicle_id', $lhf->id)->value('fms_status'))->toBe('3');
});

it('keeps the V1 types: status_updates, status_no_dispatch (400/404), dispatch_logs, unknown 400', function () {
    ['lhf' => $lhf] = emd_vehicles();
    $key = app(ApiKey::class)->current();

    emd_sync(['intraRP_API_Key' => $key, 'type' => 'status_updates', 'statuses' => [
        ['id' => 7, 'mission_number' => 5, 'sender' => 'LHF 1/44-1', 'status' => '6', 'timestamp' => '23.10.2026 14:10'],
    ]])->assertOk()->assertJson(['type' => 'status_updates', 'successful_ids' => [], 'not_found' => 1, 'total_received' => 1]);
    expect($lhf->fresh()->fms_status)->toBe('6');

    emd_sync(['intraRP_API_Key' => $key, 'type' => 'status_no_dispatch', 'vehicle_name' => 'LHF 1/44-1', 'status' => '2', 'date' => '23.10.2026', 'time' => '14:12'])
        ->assertOk()->assertExactJson(['success' => true, 'vehicle_id' => $lhf->id, 'new_status' => '2', 'source' => 'no_dispatch']);
    expect($lhf->fresh()->fms_status_source)->toBe('no_dispatch');
    emd_sync(['intraRP_API_Key' => $key, 'type' => 'status_no_dispatch', 'vehicle_name' => 'LHF 1/44-1'])->assertStatus(400);
    emd_sync(['intraRP_API_Key' => $key, 'type' => 'status_no_dispatch', 'vehicle_name' => 'Nope', 'status' => '2'])->assertNotFound();
    emd_sync(['intraRP_API_Key' => $key, 'type' => 'dispatch_logs', 'missions' => [[], []]])->assertJson(['processed' => 2]);
    emd_sync(['intraRP_API_Key' => $key, 'type' => 'x'])->assertStatus(400)->assertJson(['error' => 'Unbekannter Sync-Typ']);
});

it('acknowledges only the run below the first retryable status, integer ids only, and never sets an older FMS', function () {
    ['rtw' => $rtw, 'lhf' => $lhf] = emd_vehicles();

    emd_sync(st_body([
        ['id' => 9, 'mission_number' => 5, 'sender' => 'Unbekannt 1', 'status' => '3', 'timestamp' => '23.10.2026 14:09'],
        ['id' => 10, 'mission_number' => 902, 'sender' => 'RTW 1/83-1', 'status' => 'C', 'timestamp' => '23.10.2026 14:10'],
        ['id' => '11', 'mission_number' => 903, 'sender' => 'LHF 1/44-1', 'status' => '5', 'timestamp' => '23.10.2026 14:12'],
        ['id' => 'x', 'mission_number' => 903, 'sender' => 'LHF 1/44-1', 'status' => '5', 'timestamp' => '23.10.2026 14:12'],
    ]))->assertOk()->assertJsonPath('status_ack.successful_ids', [9]);

    emd_sync(st_body([], ['fallback_statuses' => [['vehicle_name' => 'LHF 1/44-1', 'status' => '2', 'date' => '23.10.2026', 'time' => '14:11']]]))->assertOk();
    expect($lhf->fresh()->fms_status)->toBe('5');

    $this->travelTo(now()->addMinutes(30));
    emd_sync(st_body([
        ['id' => 10, 'mission_number' => 902, 'sender' => 'RTW 1/83-1', 'status' => 'C', 'timestamp' => '23.10.2026 14:10'],
        ['id' => '11', 'mission_number' => 903, 'sender' => 'LHF 1/44-1', 'status' => '5', 'timestamp' => '23.10.2026 14:12'],
    ]))->assertJsonPath('status_ack.successful_ids', [10, 11]);
});

it('caps size and depth globally before any body decoding, on the legacy paths too', function () {
    $deep = str_repeat('[', 40).str_repeat(']', 40);
    test()->call('POST', '/api/emd-sync.php', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"a":'.$deep.'}')->assertStatus(400);
    test()->call('POST', '/api/emd/sync', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"a":"'.str_repeat('x', 2 * 1024 * 1024).'"}')->assertStatus(413);
});
