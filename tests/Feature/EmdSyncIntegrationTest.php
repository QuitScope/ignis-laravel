<?php

use App\Models\Personnel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Enotf\Enums\ProtocolOrigin;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Support\ProtocolMerger;
use Modules\FireTab\Enums\SitrepSource;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireSitrep;
use Modules\FireTab\Support\StatusQueue;

uses(RefreshDatabase::class);

require_once __DIR__.'/Emd/emdHelpers.php';

it('runs a heartbeat through protocols, statuses, patient hand-over and the return channels exactly once', function () {
    ['rtw' => $rtw, 'lhf' => $lhf] = emd_vehicles();
    $user = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $this->actingAs($user)->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => $rtw->id])->assertRedirect('/enotf/overview');

    $body = emd_fixture();
    $body['status_updates']['statuses'][] = ['id' => 813, 'mission_number' => 4711, 'sender' => 'RTW 1/83-1', 'status' => 'C', 'timestamp' => '23.10.2026 14:02'];
    emd_sync($body)->assertOk()->assertJsonPath('status_ack.successful_ids', [812, 813])->assertJsonMissingPath('patient_updates');

    $protocol = EnotfProtocol::query()->where('enr', '4711')->sole();
    expect($protocol->origin)->toBe(ProtocolOrigin::Dispatch)
        ->and(ProtocolMerger::values($protocol))->toMatchArray(['salarm' => '2026-10-23T14:02', 's3' => '2026-10-23T14:04'])
        ->and(EnotfProtocol::query()->where('enr', '4711_1')->exists())->toBeTrue()
        ->and(FireIncident::query()->sole()->number)->toBe('4711');

    $this->postJson("/enotf/p/{$protocol->enr}/patient-sync")->assertOk()->assertJsonPath('patient_sync', 'pending');
    (new FireSitrep)->forceFill(['fire_incident_id' => FireIncident::query()->sole()->id, 'reported_at' => now()->utc(),
        'text' => 'Feuer aus', 'reporter_name' => 'LHF 1/44-1', 'source' => SitrepSource::Local])->save();
    StatusQueue::push($lhf, '4', '4711');

    emd_sync($body)->assertOk()->assertJsonPath('patient_updates.4711.nachname', 'Muster')
        ->assertJsonPath('situation_reports.4711.0.text', 'Feuer aus')->assertJsonPath('status_poll.status_changes.0.status', '4');
    emd_sync($body)->assertOk()->assertJsonMissingPath('patient_updates')->assertJsonMissingPath('situation_reports')
        ->assertJsonPath('status_poll.status_changes', []);
});
