<?php

use App\Auth\ApiKey;
use App\Models\Vehicle;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Enotf\Models\EnotfProtocol;

uses(RefreshDatabase::class)->group('mariadb');

/** Exactly what ef_bridge server/enotf_billing.lua sends: POST api/enotf/billing.php, key in the JSON body, no header, no session. */
function bill(array $body = [], string $url = '/api/enotf/billing.php', array $server = []): TestResponse
{
    $body += ['intraRP_API_Key' => app(ApiKey::class)->current(), 'timestamp' => time()];

    return test()->call('POST', $url, [], [], [], $server + ['CONTENT_TYPE' => 'application/json', 'HTTP_USER_AGENT' => 'ef_bridge'], json_encode($body));
}

function bill_protocol(array $over = []): EnotfProtocol
{
    $p = (new EnotfProtocol)->forceFill($over + ['enr' => (string) random_int(100000, 999999), 'status' => 'released', 'released_at' => now(),
        'patient_last_name' => 'Muster', 'patient_first_name' => 'Max', 'patient_birthdate' => '1990-01-31', 'created_at' => now()->subHour()]);
    $p->save();

    return $p;
}

it('legacy 1: ef_bridge gets the protocols with the key in the body and they are marked billed', function () {
    $v = Vehicle::factory()->create(['name' => 'RTW 1-83-1']);
    $p = bill_protocol(['enr' => '260412_1', 'destination_code' => '2', 'vehicle_transport_id' => $v->id, 'data' => ['prot_by' => 1]]);

    bill()->assertOk()->assertExactJson(['success' => true, 'count' => 1, 'protocols' => [
        ['name' => 'Muster, Max', 'birthdate' => '1990-01-31', 'transport' => true, 'missionNumber' => '260412_1', 'protocolType' => 1, 'vehicleCallsign' => 'RTW 1-83-1'],
    ]])->assertCookieMissing(config('session.cookie'));

    $p->refresh();
    expect($p->billed_at)->not->toBeNull()->and($p->getAttribute('billing_batch'))->toHaveLength(26);
});

it('legacy 2: the header key works as well, on every path form; no timestamp is 400', function () {
    bill_protocol(['patient_birthdate' => null, 'destination_code' => '1']);
    $key = app(ApiKey::class)->current();

    bill(['intraRP_API_Key' => null], '/api/enotf/billing', ['HTTP_X-API-Key' => $key])->assertOk()
        ->assertJsonPath('count', 1)->assertJsonPath('protocols.0.birthdate', '')->assertJsonPath('protocols.0.transport', false)->assertJsonPath('protocols.0.vehicleCallsign', '');
    bill_protocol(['destination_poi_id' => null, 'destination_code' => '22']);
    bill([], '/api/enotf-billing')->assertOk()->assertJsonPath('count', 1)->assertJsonPath('protocols.0.transport', true);
    bill([], '/api/enotf-billing.php')->assertOk()->assertExactJson(['success' => true, 'count' => 0, 'protocols' => []]);
    bill(['timestamp' => null])->assertStatus(400)->assertExactJson(['success' => false, 'error' => 'Erforderliche Felder fehlen', 'message' => 'timestamp ist erforderlich']);
});

it('legacy 3: no or a wrong key gets 403 and bills nothing', function () {
    $p = bill_protocol();
    app(ApiKey::class)->current();

    bill(['intraRP_API_Key' => null])->assertForbidden();
    bill(['intraRP_API_Key' => 'falscher-schluessel'])->assertForbidden();
    bill(['intraRP_API_Key' => null], server: ['HTTP_X-API-Key' => 'falscher-schluessel'])->assertForbidden();
    expect($p->fresh()->billed_at)->toBeNull();
});

it('new 1: parallel calls get disjoint sets (a row claimed between select and update stays with the other batch)', function () {
    $a = bill_protocol(['enr' => '1001']);
    bill_protocol(['enr' => '1002']);
    bill_protocol(['enr' => '1003', 'created_at' => now()->addHour()]);

    $raced = false;
    DB::listen(function (QueryExecuted $q) use (&$raced, $a) {
        if (! $raced && str_starts_with($q->sql, 'select') && str_contains($q->sql, 'enotf_protocols') && str_contains($q->sql, 'billed_at')) {
            $raced = true;
            DB::table('enotf_protocols')->where('id', $a->id)->update(['billed_at' => now(), 'billing_batch' => 'OTHER']);
        }
    });

    $first = bill()->assertOk()->json('protocols.*.missionNumber');
    $second = bill()->assertOk()->json('protocols.*.missionNumber');

    expect($first)->toBe(['1002'])->and($second)->toBe([])->and($a->fresh()->getAttribute('billing_batch'))->toBe('OTHER');
});

it('new 2: withdrawn (crew-deleted), hidden and open protocols are never billed', function () {
    bill_protocol(['status' => 'withdrawn', 'withdrawn_at' => now()]);
    bill_protocol(['hidden_at' => now()]);
    bill_protocol(['status' => 'open', 'released_at' => null]);

    bill()->assertOk()->assertJsonPath('count', 0);
    expect(EnotfProtocol::whereNotNull('billed_at')->count())->toBe(0);
});

it('new 3: a protocol billed by the import (billed_at set, no batch) is never delivered again', function () {
    $old = bill_protocol(['billed_at' => now()->subYear()]);

    bill()->assertOk()->assertJsonPath('count', 0);
    expect($old->fresh()->getAttribute('billing_batch'))->toBeNull();
});

it('names a patient without a last name without a leading comma', function () {
    bill_protocol(['patient_last_name' => null]);

    bill()->assertOk()->assertJsonPath('protocols.0.name', 'Max');
});
