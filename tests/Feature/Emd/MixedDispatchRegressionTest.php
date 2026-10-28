<?php

use App\Auth\ApiKey;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireSitrep;
use Modules\FireTab\Support\OrganizationTime;

uses(RefreshDatabase::class);

require_once __DIR__.'/emdHelpers.php';

it('mixed RTW + NEF + LHF dispatch: two protocols for the EMS vehicles, one fire incident with the LHF only, idempotent', function () {
    ['rtw' => $rtw, 'nef' => $nef, 'lhf' => $lhf] = emd_vehicles();
    $body = emd_fixture();

    emd_sync($body)->assertOk()->assertJsonPath('success', true)
        ->assertJsonPath('statistics.unique_dispatches', 1)->assertJsonPath('statistics.created_entries', 2)
        ->assertJsonPath('statistics.created_fire_incidents', 1)->assertJsonPath('statistics.new_sitreps_from_dispatch', 2)
        ->assertJsonPath('status_ack.successful_ids', [812])->assertCookieMissing(config('session.cookie'));

    $protocols = EnotfProtocol::query()->orderBy('enr')->get();
    expect($protocols->pluck('enr')->all())->toBe(['4711', '4711_1']);
    [$first, $second] = $protocols->all();
    expect($first->vehicle_transport_id)->toBe($rtw->id)->and($first->vehicle_na_id)->toBeNull()
        ->and($first->vehicle_transport_label)->toBe('rtw-1-83-1')->and($first->data)->toMatchArray(['prot_by' => 0, 'sonderrechte_anfahrt' => 'ja'])
        ->and($second->vehicle_na_id)->toBe($nef->id)->and($second->vehicle_transport_id)->toBeNull()->and($second->data['prot_by'])->toBe(1);
    foreach ($protocols as $p) {
        expect($p->origin->value)->toBe('dispatch')->and($p->patient_first_name)->toBe('Max')->and($p->patient_last_name)->toBe('Muster')
            ->and($p->patient_birthdate?->format('m-d'))->toBe('01-01')->and($p->created_by_user_id)->toBeNull();
    }
    expect(EnotfProtocol::query()->where('vehicle_transport_id', $lhf->id)->orWhere('vehicle_na_id', $lhf->id)->exists())->toBeFalse();

    $incident = FireIncident::query()->sole();
    expect($incident->number)->toBe('4711')->and($incident->location)->toBe('1023')->and($incident->keyword)->toBe('B2 Y')
        ->and($incident->vehicles()->pluck('vehicle_id')->all())->toBe([$lhf->id]);
    expect(FireSitrep::query()->orderBy('reported_at')->get(['text', 'is_important'])->toArray())->toBe([
        ['text' => 'Rauch aus Fenster 1. OG', 'is_important' => false], ['text' => 'Gasgeruch im Treppenhaus', 'is_important' => true],
    ]);

    // The status of the dispatch created in this very sync applies at once (protocol time + vehicle FMS);
    // the fallback status in the ef_bridge shape {entries} sets the LHF (legacy lost it).
    $s3 = CarbonImmutable::parse(OrganizationTime::utc('23.10.2026', '14:04'), 'UTC')->format('Y-m-d\TH:i:s\Z');
    expect($first->data['s3'] ?? null)->toBe($s3)->and($first->revision)->toBe(2)
        ->and($rtw->fresh()->only(['fms_status', 'fms_status_source']))->toBe(['fms_status' => '3', 'fms_status_source' => 'dispatch'])
        ->and($lhf->fresh()->only(['fms_status', 'fms_status_source']))->toBe(['fms_status' => '2', 'fms_status_source' => 'no_dispatch']);

    emd_sync($body)->assertOk()->assertJsonPath('statistics.created_entries', 0)->assertJsonPath('statistics.created_fire_incidents', 0)
        ->assertJsonPath('status_ack.successful_ids', [812]);
    expect($first->fresh()->revision)->toBe(2);
    expect(EnotfProtocol::query()->count())->toBe(2)->and(FireIncident::query()->count())->toBe(1)
        ->and($incident->vehicles()->count())->toBe(1)->and(FireSitrep::query()->count())->toBe(2);
});

it('pure fire dispatch creates no protocol', function () {
    emd_vehicles();
    $body = emd_fixture();
    $body['dispatch_data']['vehicles'] = [['value' => 'LHF 1/44-1', 'dispatch' => 4712, 'source' => 3]];

    emd_sync($body)->assertOk()->assertJsonPath('statistics.created_entries', 0)->assertJsonPath('statistics.created_fire_incidents', 1);
    expect(EnotfProtocol::query()->count())->toBe(0)->and(FireIncident::query()->sole()->number)->toBe('4712');
});

it('the old alias reaches the same route; no key is 403 and writes nothing', function () {
    emd_vehicles();
    $body = emd_fixture();

    emd_sync(['intraRP_API_Key' => null] + $body)->assertForbidden();
    emd_sync(['intraRP_API_Key' => 'falsch'] + $body, '/api/emd-sync')->assertForbidden();
    expect(EnotfProtocol::query()->count())->toBe(0)->and(FireIncident::query()->count())->toBe(0)
        ->and(app(Settings::class)->has('emd.last_sync_at'))->toBeFalse();

    test()->call('POST', '/api/emd/sync', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X-API-Key' => $body['intraRP_API_Key']], '[1]')
        ->assertStatus(400)->assertExactJson(['success' => false, 'error' => 'Ungültiges JSON']);
    emd_sync($body, '/api/emd-sync.php')->assertOk()->assertJsonPath('statistics.created_entries', 2);
    expect(app(Settings::class)->has('emd.last_sync_at'))->toBeTrue();
});

it('a registry-only body gets the short answer; an oversized one clears the request, rings the bell and is 422', function () {
    $settings = app(Settings::class);
    $settings->set('vehicles.registry_requested_at', now()->toIso8601String());

    emd_sync(emd_fixture('vehicle_registry'))->assertOk()->assertExactJson([
        'success' => true, 'message' => 'Vehicle Registry empfangen: 8 Fahrzeuge zur Prüfung bereit', 'vehicle_registry_received' => 8,
    ]);
    expect($settings->has('vehicles.registry_requested_at'))->toBeFalse();

    $settings->set('vehicles.registry_requested_at', now()->toIso8601String());
    $admin = User::factory()->create(['is_super_admin' => true]);
    $big = ['intraRP_API_Key' => app(ApiKey::class)->current(), 'timestamp' => 1, 'vehicle_registry' => array_fill(0, 5001, ['value' => 'X'])];
    emd_sync($big)->assertStatus(422)->assertExactJson(['success' => false, 'error' => 'too_many_rows']);
    expect($settings->has('vehicles.registry_requested_at'))->toBeFalse()
        ->and($admin->notifications()->sole()->data['link'])->toBe('/vehicles/registry');
});

it('one bad dispatch is skipped, not a 500: long text clipped, a non-string sitrep type ignored, an exception rolls back only that dispatch', function () {
    emd_vehicles();
    $body = emd_fixture();
    $body['dispatch_data']['vehicles'][0]['dispatch_data']['postal'] = str_repeat('x', 300);
    $body['dispatch_data']['vehicles'][0]['dispatch_data']['lagemeldungen'][] = ['type' => ['x'], 'text' => 'y', 'date' => '23.10.2026', 'time' => '14:03'];

    emd_sync($body)->assertOk()->assertJsonPath('statistics.created_fire_incidents', 1);
    expect(mb_strlen(FireIncident::query()->sole()->location))->toBe(255);

    FireIncident::query()->delete();
    EnotfProtocol::query()->delete();
    FireIncident::creating(fn () => throw new RuntimeException('boom'));
    emd_sync($body)->assertOk()->assertJsonPath('statistics.skipped_dispatches', 1)
        ->assertJsonPath('statistics.processed_dispatches', 0);
    expect(EnotfProtocol::query()->count())->toBe(0);
});
