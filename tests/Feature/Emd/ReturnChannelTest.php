<?php

use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Enotf\Enums\PatientSync;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\FireTab\Enums\SitrepSource;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireSitrep;
use Modules\FireTab\Support\OrganizationTime;
use Modules\FireTab\Support\StatusQueue;

uses(RefreshDatabase::class);

require_once __DIR__.'/emdHelpers.php';

it('hands patient data, local sitreps and FMS commands back exactly once', function () {
    ['rtw' => $rtw, 'lhf' => $lhf] = emd_vehicles();
    $body = emd_fixture();
    emd_sync($body)->assertOk()->assertJsonMissingPath('patient_updates')->assertJsonMissingPath('situation_reports')
        ->assertJsonPath('status_poll.status_changes', []);

    EnotfProtocol::query()->where('enr', '4711')->update(['patient_sync' => PatientSync::Pending, 'patient_birthdate' => now()->subYears(40)->subDay()->toDateString()]);
    $sitrep = (new FireSitrep)->forceFill(['fire_incident_id' => FireIncident::query()->sole()->id, 'reported_at' => now()->utc(),
        'text' => 'Feuer aus', 'reporter_name' => 'LHF 1/44-1', 'source' => SitrepSource::Local]);
    $sitrep->save();
    StatusQueue::push($lhf, '4', '4711');

    $at = OrganizationTime::local($sitrep->reported_at);
    emd_sync($body)->assertOk()
        ->assertJsonPath('patient_updates.4711', ['vorname' => 'Max', 'nachname' => 'Muster', 'alter' => 40, 'funkrufname' => 'RTW 1/83-1', 'transportziel' => null])
        ->assertJsonPath('situation_reports.4711', [['text' => 'Feuer aus', 'time' => $at->format('H:i'), 'date' => $at->format('d.m.Y'), 'sender' => 'LHF 1/44-1']])
        ->assertJsonPath('status_changes.0.vehicle_name', 'LHF 1/44-1')->assertJsonPath('status_poll.status_changes.0.status', '4');
    expect(EnotfProtocol::query()->where('enr', '4711')->value('patient_sync'))->toBe(PatientSync::Synced);

    emd_sync($body)->assertOk()->assertJsonMissingPath('patient_updates')->assertJsonMissingPath('situation_reports')
        ->assertJsonMissingPath('status_changes')->assertJsonPath('status_poll.status_changes', []);

    $this->actingAs(User::factory()->create())->getJson('/enotf/status')->assertOk()
        ->assertJsonPath('emd_last_sync_at', app(Settings::class)->get('emd.last_sync_at'));
});
