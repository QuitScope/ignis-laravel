<?php

use App\Jobs\SendDiscordWebhook;
use App\Models\Personnel;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\InboxNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Modules\Enotf\Enums\ProtocolOrigin;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Events\ProtocolReleased;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Support\ProtocolMerger;

uses(RefreshDatabase::class);

it('runs a protocol from login to print and keeps the withdraw rules', function () {
    Queue::fake();
    Notification::fake();
    $events = 0;
    Event::listen(ProtocolReleased::class, function () use (&$events) {
        $events++;
    });
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS, 'identifier' => 'RTW-9A']);
    $user = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $mate = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);

    $this->actingAs($user)->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => $vehicle->id, 'codriver_id' => $mate->personnel_id])->assertRedirect('/enotf/overview');
    $this->postJson('/enotf/protocols', ['enr' => '900001', 'prot_by' => 0])->assertCreated()->assertJsonPath('url', '/enotf/p/900001');
    $p = EnotfProtocol::query()->where('enr', '900001')->firstOrFail();
    expect($p->vehicle_transport_id)->toBe($vehicle->id)->and($p->crew()->pluck('personnel_id')->all())->toContain($mate->personnel_id);
    $this->get('/enotf/p/900001')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Enotf/Protocol'));

    $full = json_decode((string) file_get_contents(base_path('tests/Fixtures/enotf-conditions.json')), true)['full'];
    unset($full['medis'], $full['pfname']);
    $full = ['awsicherung_neu' => 1, 'prot_by' => 0] + $full;
    $half = (int) ceil(count($full) / 2);
    $rev = $p->revision;
    $this->patchJson('/enotf/p/900001', ['revision' => $rev, 'fields' => array_slice($full, 0, $half, true)])->assertOk();
    $this->patchJson('/enotf/p/900001', ['revision' => $rev, 'fields' => array_slice($full, $half, null, true)])->assertOk()->assertJsonPath('conflicts', []);
    $values = ProtocolMerger::values($p->fresh());
    expect(array_keys(array_filter(array_intersect_key($values, $full), fn ($v) => $v === null)))->toBe([]);

    $this->getJson('/enotf/p/900001/plausibility')->assertOk()->assertJsonPath('count', 1);
    $this->postJson('/enotf/p/900001/release')->assertStatus(422)->assertJsonPath('violations.0.rule', 'pfname');
    $this->patchJson('/enotf/p/900001', ['revision' => $p->fresh()->revision, 'fields' => ['pfname' => 'Mustermann']])->assertOk();
    $this->getJson('/enotf/p/900001/plausibility')->assertOk()->assertJsonPath('count', 0);

    $this->postJson('/enotf/p/900001/release')->assertOk();
    $this->postJson('/enotf/p/900001/release')->assertStatus(409);
    expect($events)->toBe(1)->and($p->fresh()->status)->toBe(ProtocolStatus::Released);
    Queue::assertPushed(SendDiscordWebhook::class, 1);
    Notification::assertSentToTimes($mate, InboxNotification::class, 1);
    Notification::assertNothingSentTo($user);

    $this->get('/enotf/print/900001')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Enotf/Print'));

    $this->delete('/enotf/p/900001')->assertForbidden();
    $dispatch = (new EnotfProtocol)->forceFill(['enr' => '900002', 'origin' => ProtocolOrigin::Dispatch, 'vehicle_transport_id' => $vehicle->id]);
    $dispatch->save();
    $this->postJson('/enotf/protocols', ['enr' => '900003', 'prot_by' => 0])->assertCreated();
    $this->delete('/enotf/p/900002')->assertForbidden();
    $this->post('/enotf/protocols/withdraw-all')->assertRedirect();
    expect(EnotfProtocol::query()->where('enr', '900003')->first()?->status)->toBe(ProtocolStatus::Withdrawn)
        ->and($dispatch->fresh()->status)->toBe(ProtocolStatus::Open)
        ->and($p->fresh()->status)->toBe(ProtocolStatus::Released);
});
