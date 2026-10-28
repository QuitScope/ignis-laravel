<?php

use App\Models\Personnel;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\InboxNotification;
use App\Realtime\Pulse;
use App\Realtime\Realtime;
use App\Vehicles\FmsStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\FireTab\Events\AsuEventRecorded;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Support\ManvLog;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Emd/emdHelpers.php';

beforeEach(function () {
    config(['broadcasting.default' => 'reverb']);
    Event::fake([Pulse::class]);
});

/** Runs what `defer` collected (an HTTP test request does this itself when it terminates). @return list<string> "channel topic" */
function p11_pulses(): array
{
    app(DeferredCallbackCollection::class)->invoke();
    $sent = [];
    Event::assertDispatched(Pulse::class, function (Pulse $p) use (&$sent) {
        $sent[] = "{$p->channel} {$p->topic}";

        return true;
    });

    return $sent;
}

it('pulses the bell, the FMS status, the ASU board, the MANV board/list and the eNOTF vehicle', function () {
    $user = User::factory()->create();
    $user->notify(new InboxNotification('system', 'Titel', 'Text', null));
    $fire = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    FmsStatus::set($fire, '3', 'button');
    AsuEventRecorded::dispatch(7, 99);
    $manv = (new ManvIncident)->forceFill(['incident_number' => 'L-1', 'location' => 'Halle', 'started_at' => now()]);
    $manv->save();
    ManvLog::record($manv, 'patient_angelegt', 'Patient angelegt');
    $ems = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS]);
    (new EnotfProtocol)->forceFill(['enr' => '4711', 'vehicle_transport_id' => $ems->id])->save();

    expect(p11_pulses())->toEqualCanonicalizing([
        "user.{$user->id} inbox", "firetab.vehicle.{$fire->id} status", 'firetab.incident.7 asu',
        'manv list', "manv.{$manv->id} board", "enotf.vehicle.{$ems->id} status",
    ]);
});

it('pulses the eNOTF rows when a vital sign is written (no revision)', function () {
    $this->seed(RoleSeeder::class);
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS]);
    $this->actingAs(User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]))
        ->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => $vehicle->id])->assertRedirect('/enotf/overview');
    (new EnotfProtocol)->forceFill(['enr' => '4712', 'vehicle_transport_id' => $vehicle->id])->save();

    $this->postJson('/enotf/p/4712/vitals', ['values' => ['spo2' => '97']])->assertCreated();

    expect(p11_pulses())->toContain("enotf.vehicle.{$vehicle->id} rows");
});

it('sends one pulse per channel and topic for an EMD sync touching two vehicles of one dispatch', function () {
    $v = emd_vehicles();
    emd_sync(emd_fixture())->assertOk();

    $sent = [];
    Event::assertDispatched(Pulse::class, function (Pulse $p) use (&$sent) {
        $sent[] = "{$p->channel} {$p->topic}";

        return true;
    });
    expect($sent)->toBe(array_values(array_unique($sent)))
        ->toContain("enotf.vehicle.{$v['rtw']->id} status", "enotf.vehicle.{$v['nef']->id} status", "firetab.vehicle.{$v['lhf']->id} incidents");
});

it('sends nothing with the flag off or after a rollback', function () {
    config(['broadcasting.default' => 'null']);
    Realtime::pulse('manv', 'list');
    config(['broadcasting.default' => 'reverb']);
    try {
        DB::transaction(function () {
            Realtime::pulse('manv', 'list');
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    app(DeferredCallbackCollection::class)->invoke();

    Event::assertNotDispatched(Pulse::class);
});

it('stops sending for 30 s after the first failed pulse (circuit breaker)', function () {
    $this->mock(BroadcastFactory::class)->shouldReceive('queue')->once()->andThrow(new RuntimeException('hung'));
    Event::swap(new Dispatcher(app()));

    Realtime::pulse('user.1', 'inbox');
    Realtime::pulse('user.2', 'inbox');
    app(DeferredCallbackCollection::class)->invoke();

    expect(Cache::has('realtime.down'))->toBeTrue();
});

it('carries only topic and an integer id', function () {
    expect((new Pulse('manv', 'list', 5))->broadcastWith())->toBe(['topic' => 'list', 'id' => 5])
        ->and((new Pulse('user.1', 'inbox'))->broadcastWith())->toBe(['topic' => 'inbox'])
        ->and((new Pulse('user.1', 'inbox'))->broadcastAs())->toBe('pulse')
        ->and((new Pulse('user.1', 'inbox'))->broadcastOn()->name)->toBe('private-user.1');
});
