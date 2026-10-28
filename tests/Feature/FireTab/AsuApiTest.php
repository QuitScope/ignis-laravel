<?php

use App\Models\Personnel;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\FireTab\Events\AsuEventRecorded;
use Modules\FireTab\Models\AsuEvent;
use Modules\FireTab\Models\AsuMember;
use Modules\FireTab\Models\AsuTrupp;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentLog;
use Modules\FireTab\Models\FireIncidentVehicle;

uses(RefreshDatabase::class)->group('mariadb');

function asu_crew(object $t, ?Vehicle $vehicle = null, ?User $user = null): Vehicle
{
    $user ??= User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $vehicle ??= Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    $t->actingAs($user)->withSession(['crew.firetab' => ['user_id' => $user->id, 'vehicle_id' => $vehicle->id, 'personnel_id' => $user->personnel_id, 'since' => time()]]);

    return $vehicle;
}

function asu_incident(?Vehicle $vehicle = null, array $over = []): FireIncident
{
    $i = (new FireIncident)->forceFill(['location' => 'Hauptstr. 1', 'keyword' => 'B3', 'started_at' => now(), ...$over]);
    $i->save();
    if ($vehicle !== null) {
        (new FireIncidentVehicle)->forceFill(['fire_incident_id' => $i->id, 'vehicle_id' => $vehicle->id, 'name' => $vehicle->name])->save();
    }

    return $i->fresh();
}

function asu_trupp(object $t, FireIncident $i): int
{
    return $t->postJson("/firetab/incidents/{$i->id}/asu/trupps", [
        'name' => 'AT 1/42-1', 'role' => 'angriff', 'task' => 'Innenangriff 1. OG', 'expected_minutes' => 30,
        'members' => [['position' => 'tf', 'name' => 'Weber, M'], ['position' => 'tm1', 'name' => 'Krause, J']],
    ])->assertCreated()->json('trupp_id');
}

function asu_event(object $t, FireIncident $i, int $trupp, string $type, array $extra = [])
{
    return $t->postJson("/firetab/incidents/{$i->id}/asu/events", ['trupp_id' => $trupp, 'type' => $type, 'client_uuid' => (string) Str::uuid(), ...$extra]);
}

it('creates a Trupp and shows it with events, device, server time and since filter', function () {
    $vehicle = asu_crew($this);
    $i = asu_incident($vehicle);
    $trupp = asu_trupp($this, $i);
    $first = asu_event($this, $i, $trupp, 'angeschlossen', ['bar' => 300])->assertOk()->json('event_id');
    asu_event($this, $i, $trupp, 'druck', ['bar' => 250, 'member_id' => AsuMember::where('trupp_id', $trupp)->value('id')])->assertOk();

    $state = $this->getJson("/firetab/incidents/{$i->id}/asu/state")->assertOk();
    expect($state->json('trupps.0.name'))->toBe('AT 1/42-1')
        ->and(array_column($state->json('trupps.0.members'), 'position'))->toBe(['tf', 'tm1'])
        ->and($state->json('events.0'))->toMatchArray(['type' => 'angeschlossen', 'bar' => 300, 'device' => $vehicle->name, 'voided' => false])
        ->and($state->json('now'))->toBeInt()
        ->and($state->json('writable'))->toBeTrue()
        ->and(FireIncidentLog::where('fire_incident_id', $i->id)->where('action', 'asu_angeschlossen')->value('description'))->toBe('AT 1/42-1')
        ->and(FireIncidentLog::where('action', 'like', 'asu_%')->count())->toBe(1);

    $etag = $state->json('trupps_etag');
    $next = $this->getJson("/firetab/incidents/{$i->id}/asu/state?since=$first&trupps_etag=$etag")->assertOk();
    expect($next->json('trupps'))->toBeNull()->and($next->json('events'))->toHaveCount(1)->and($next->json('events.0.type'))->toBe('druck');
});

it('keeps one row for the same uuid sent twice and for a parallel duplicate, per incident', function () {
    Event::fake([AsuEventRecorded::class]);
    $vehicle = asu_crew($this);
    $i = asu_incident($vehicle);
    $trupp = asu_trupp($this, $i);
    $uuid = '0B6D7C1E-5A4F-4C2B-9E8D-1F2A3B4C5D6E';

    $a = asu_event($this, $i, $trupp, 'ziel', ['client_uuid' => $uuid, 'bar' => 230])->assertOk()->json();
    $b = asu_event($this, $i, $trupp, 'ziel', ['client_uuid' => strtolower($uuid), 'bar' => 230])->assertOk()->json();
    expect($b)->toBe(['event_id' => $a['event_id'], 'duplicate' => true])
        ->and(AsuEvent::count())->toBe(1);
    Event::assertDispatchedTimes(AsuEventRecorded::class, 1);

    $parallel = '11111111-2222-4333-8444-555555555555';
    $armed = true;
    DB::listen(function ($q) use (&$armed, $i, $trupp, $parallel) {
        if ($armed && str_starts_with($q->sql, 'select') && str_contains($q->sql, 'fire_asu_trupps')) {
            $armed = false;
            DB::table('fire_asu_events')->insert(['fire_incident_id' => $i->id, 'trupp_id' => $trupp, 'type' => 'mayday', 'client_uuid' => $parallel, 'occurred_at' => now(), 'received_at' => now()]);
        }
    });
    $c = asu_event($this, $i, $trupp, 'mayday', ['client_uuid' => $parallel])->assertOk()->json();
    expect($c['duplicate'])->toBeTrue()->and(AsuEvent::where('client_uuid', $parallel)->count())->toBe(1);

    $other = asu_incident($vehicle);
    $d = asu_event($this, $other, asu_trupp($this, $other), 'ziel', ['client_uuid' => $uuid])->assertOk()->json();
    expect($d['duplicate'])->toBeFalse()->and($d['event_id'])->not->toBe($a['event_id']);
});

it('stores device time inside the accepted window and received time outside', function () {
    $vehicle = asu_crew($this);
    $i = asu_incident($vehicle);
    $trupp = asu_trupp($this, $i);
    $this->freezeSecond();

    asu_event($this, $i, $trupp, 'angeschlossen', ['occurred_at' => now()->subMinutes(2)->getTimestampMs()])->assertOk();
    asu_event($this, $i, $trupp, 'draussen', ['occurred_at' => now()->addMinute()->getTimestampMs()])->assertOk();
    [$in, $out] = AsuEvent::orderBy('id')->get()->all();

    expect($in->occurred_at->equalTo(now()->subMinutes(2)))->toBeTrue()->and($in->received_at->equalTo(now()))->toBeTrue()
        ->and($out->occurred_at->equalTo(now()))->toBeTrue();
});

it('validates ranges, the member of the Trupp and the mayday note', function () {
    $vehicle = asu_crew($this);
    $i = asu_incident($vehicle);
    $trupp = asu_trupp($this, $i);
    $foreignMember = AsuMember::where('trupp_id', asu_trupp($this, $i))->value('id');

    asu_event($this, $i, $trupp, 'druck', ['bar' => 401])->assertJsonValidationErrors('bar');
    asu_event($this, $i, $trupp, 'druck', ['bar' => 250, 'member_id' => $foreignMember])->assertJsonValidationErrors('member_id');
    asu_event($this, $i, $trupp, 'unbekannt')->assertJsonValidationErrors('type');
    asu_event($this, $i, $trupp, 'ziel', ['occurred_at' => PHP_INT_MAX])->assertJsonValidationErrors('occurred_at');
    asu_event($this, $i, $trupp, 'hinweis_13', ['note' => str_repeat('a', 256)])->assertJsonValidationErrors('note');
    asu_event($this, $i, $trupp, 'mayday', ['note' => 'kein json'])->assertJsonValidationErrors('note');
    asu_event($this, $i, $trupp, 'mayday', ['note' => json_encode(['member' => 'A', 'x' => 'y'])])->assertJsonValidationErrors('note');
    asu_event($this, $i, $trupp, 'mayday', ['note' => json_encode(['member' => ['A']])])->assertJsonValidationErrors('note');
    expect(AsuEvent::count())->toBe(0);

    asu_event($this, $i, $trupp, 'mayday', ['note' => json_encode(['member' => 'Krause, J', 'standort' => '1. OG', 'lage' => 'eingeklemmt'])])->assertOk();
    expect(FireIncidentLog::where('action', 'asu_mayday')->where('description', 'AT 1/42-1')->whereNull('user_id')->exists())->toBeTrue();
});

it('voids events, keeps them visible, and deletes a Trupp only without valid events', function () {
    $user = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $vehicle = asu_crew($this, null, $user);
    $i = asu_incident($vehicle);
    $trupp = asu_trupp($this, $i);
    $event = asu_event($this, $i, $trupp, 'rueckzug')->json('event_id');
    $url = "/firetab/incidents/{$i->id}/asu";

    $this->deleteJson("$url/trupps/$trupp")->assertJsonValidationErrors('trupp');
    $this->postJson("$url/events/$event/void")->assertOk();
    $this->postJson("$url/events/$event/void")->assertOk();

    $state = $this->getJson("$url/state?since=$event")->json();
    expect($state['voided'])->toBe([$event])
        ->and(AsuEvent::find($event))->voided_by->toBe($user->id)->voided_at->not->toBeNull();

    $this->deleteJson("$url/trupps/$trupp")->assertOk();
    expect(AsuTrupp::find($trupp))->toBeNull();

    $other = asu_incident($vehicle);
    $foreign = asu_trupp($this, $other);
    $this->deleteJson("$url/trupps/$foreign")->assertNotFound();
    $this->putJson("$url/trupps/$foreign", [])->assertNotFound();
});

it('lets only the assigned crew write an open incident; QM reads crew-free', function () {
    $vehicle = asu_crew($this);
    $i = asu_incident($vehicle);
    $trupp = asu_trupp($this, $i);
    $url = "/firetab/incidents/{$i->id}/asu";

    asu_crew($this);
    $this->getJson("$url/state")->assertForbidden();
    asu_event($this, $i, $trupp, 'draussen')->assertForbidden();
    $this->postJson("$url/trupps", [])->assertForbidden();

    asu_crew($this, null, User::factory()->superAdmin()->create(['personnel_id' => Personnel::factory()->create()->id]));
    $this->getJson("$url/state")->assertOk()->assertJsonPath('writable', false);
    asu_event($this, $i, $trupp, 'draussen')->assertForbidden();

    asu_crew($this, $vehicle);
    $i->forceFill(['finalized_at' => now()])->save();
    $this->getJson("$url/state")->assertOk()->assertJsonPath('writable', false);
    asu_event($this, $i, $trupp, 'draussen')->assertForbidden();
    $this->deleteJson("$url/trupps/$trupp")->assertForbidden();

    $this->actingAs(User::factory()->superAdmin()->create())->withSession([]);
    $this->getJson("/firetab/qm/incidents/{$i->id}/asu/state")->assertOk()->assertJsonPath('trupps.0.id', $trupp);
    expect(AsuEvent::count())->toBe(0);
});

it('gives the show pages the ASU url and config: write url for the assigned crew, QM url for QM', function () {
    $vehicle = asu_crew($this);
    $i = asu_incident($vehicle);

    $this->get("/firetab/incidents/{$i->id}?tab=atemschutz")->assertInertia(fn ($p) => $p->component('FireTab/Show')
        ->where('can.write', true)->where('asu.url', "/firetab/incidents/{$i->id}/asu")->where('asu.config.start_bar', 300)->has('asu.config.consumption'));

    $this->actingAs(User::factory()->superAdmin()->create())->withSession([]);
    $this->get("/firetab/qm/incidents/{$i->id}")->assertInertia(fn ($p) => $p->component('FireTab/Qm/Show')
        ->where('asu.url', "/firetab/qm/incidents/{$i->id}/asu")->has('asu.config.start_bar'));
});

it('shows the Registrierung of the ASÜ events in the report tab, voided events left out', function () {
    config(['ignis.timezone' => 'Europe/Berlin']);
    $this->travelTo(CarbonImmutable::parse('2026-07-01 10:00:00', 'UTC'));
    $i = asu_incident(asu_crew($this));
    $trupp = asu_trupp($this, $i);
    asu_event($this, $i, $trupp, 'angeschlossen', ['bar' => 300])->assertOk();
    $this->travel(5)->minutes();
    $wrong = asu_event($this, $i, $trupp, 'ziel', ['bar' => 280])->json('event_id');
    $this->postJson("/firetab/incidents/{$i->id}/asu/events/$wrong/void")->assertOk();
    $this->travel(5)->minutes();
    asu_event($this, $i, $trupp, 'ziel', ['bar' => 240])->assertOk();
    $this->travel(15)->minutes();
    asu_event($this, $i, $trupp, 'draussen')->assertOk();

    $this->get("/firetab/incidents/{$i->id}?tab=bericht")->assertInertia(fn ($p) => $p->component('FireTab/Show')->has('registration', 1)
        ->where('registration.0.members', ['Weber, M', 'Krause, J'])
        ->where('registration.0.angeschlossen', '12:00')->where('registration.0.start_bar', 300)
        ->where('registration.0.ziel', '12:10')->where('registration.0.ziel_bar', 240)
        ->where('registration.0.draussen', '12:25')->where('registration.0.minutes', 25)->where('registration.0.mayday', null));
});
