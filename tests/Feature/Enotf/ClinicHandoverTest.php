<?php

use App\Discord\WebhookKind;
use App\Jobs\SendDiscordWebhook;
use App\Models\Personnel;
use App\Models\Poi;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Modules\Enotf\Models\ClinicCode;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\HospitalAccessCode;
use Modules\Enotf\Models\Preregistration;

uses(RefreshDatabase::class);

function ch_crew(): array
{
    $user = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS, 'name' => 'RTW 1']);
    test()->actingAs($user)->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => $vehicle->id])->assertRedirect('/enotf/overview');
    $p = (new EnotfProtocol)->forceFill(['enr' => (string) random_int(100000, 999999), 'vehicle_transport_id' => $vehicle->id,
        'vehicle_transport_label' => 'RTW 1', 'patient_last_name' => 'Geheim', 'data' => ['diagnose' => 'Apoplex']]);
    $p->save();

    return [$user, $p];
}

function ch_device(Poi $poi): void
{
    (new HospitalAccessCode)->forceFill(['poi_id' => $poi->id, 'code_hash' => Hash::make('ABCDEFGHJKLMNPQR'), 'rotated_at' => now()])->save();
    test()->post('/enotf/schnittstelle/login', ['poi_id' => $poi->id, 'code' => 'ABCDEFGHJKLMNPQR'])->assertRedirect('/enotf/schnittstelle');
}

it('gives the clinic read access to exactly the one protocol of its code, for one hour', function () {
    [$user, $p] = ch_crew();
    $other = (new EnotfProtocol)->forceFill(['enr' => 'OTHER-1']);
    $other->save();
    $this->postJson("/enotf/p/{$other->enr}/clinic-code")->assertForbidden();

    $code = $this->postJson("/enotf/p/{$p->enr}/clinic-code")->assertCreated()->json('code');
    [$id, $secret] = explode('-', $code);
    expect($secret)->toMatch('/^[A-Z2-9]{10}$/')->and(ClinicCode::query()->sole()->code_hash)->not->toContain($secret);
    auth()->logout();

    $this->post('/enotf/schnittstelle/klinikcode', ['code' => $id.'-AAAAAAAAAA'])->assertSessionHasErrors(['code' => __('enotf.clinic_code.invalid')]);
    $this->post('/enotf/schnittstelle/klinikcode', ['code' => ($id + 1).'-'.$secret])->assertSessionHasErrors(['code' => __('enotf.clinic_code.invalid')]);
    $this->get('/enotf/schnittstelle/protocol')->assertRedirect('/enotf/schnittstelle/klinikcode');

    $this->post('/enotf/schnittstelle/klinikcode', ['code' => ' '.strtolower($code)])->assertRedirect('/enotf/schnittstelle/protocol');
    $this->get('/enotf/schnittstelle/protocol')->assertOk()->assertInertia(fn ($page) => $page->component('Enotf/Print')
        ->where('protocol.enr', $p->enr)->where('clinic', true));
    $this->get("/enotf/print/{$other->enr}")->assertRedirect('/login');
    $this->get("/enotf/p/{$p->enr}")->assertRedirect('/login');
    $this->get('/enotf/schnittstelle')->assertRedirect('/enotf/schnittstelle/login');

    $this->travel(61)->minutes();
    $this->get('/enotf/schnittstelle/protocol')->assertRedirect('/enotf/schnittstelle/klinikcode');
    $this->post('/enotf/schnittstelle/klinikcode', ['code' => $code])->assertSessionHasErrors('code');
});

it('throttles clinic code redemption to 10 per minute per IP', function () {
    foreach (range(1, 10) as $i) {
        $this->post('/enotf/schnittstelle/klinikcode', ['code' => '1-AAAAAAAAAA'])->assertSessionHasErrors('code');
    }
    $this->post('/enotf/schnittstelle/klinikcode', ['code' => '1-AAAAAAAAAA'])->assertStatus(429);
});

it('pre-registers without diagnosis in Discord; the board is only for the clinic device and GET changes nothing', function () {
    Queue::fake();
    [$user, $p] = ch_crew();
    [$hospital, $otherHospital] = [Poi::factory()->create(['type' => 'Krankenhaus', 'is_active' => true, 'name' => 'Klinikum']), Poi::factory()->create(['type' => 'Klinik', 'is_active' => true])];

    $this->post("/enotf/p/{$p->enr}/prereg", ['poi_id' => $hospital->id, 'priority' => 2, 'eta_minutes' => 10, 'note' => 'V.a. Apoplex'])->assertRedirect();
    $this->post("/enotf/p/{$p->enr}/prereg", ['poi_id' => Poi::factory()->create(['type' => 'Rettungswache'])->id, 'priority' => 0])->assertSessionHasErrors('poi_id');
    $prereg = Preregistration::query()->sole();
    $foreign = (new Preregistration)->forceFill(['enotf_protocol_id' => $p->id, 'poi_id' => $otherHospital->id, 'expires_at' => now()->addHour()]);
    $foreign->save();

    Queue::assertPushed(SendDiscordWebhook::class, function (SendDiscordWebhook $job) {
        $json = json_encode($job->embed, JSON_UNESCAPED_UNICODE);

        return $job->kind === WebhookKind::EnotfPrereg
            && array_column($job->embed['fields'], 'name') === ['Priorität', 'Ankunft', 'Fahrzeug', 'Zielklinik']
            && ! str_contains($json, 'Apoplex') && ! str_contains($json, 'Geheim') && str_contains($json, 'Klinikum');
    });

    $this->get('/enotf/schnittstelle')->assertRedirect('/enotf/schnittstelle/login');
    $this->getJson('/enotf/schnittstelle/prereg')->assertForbidden();
    auth()->logout();

    ch_device($hospital);
    $before = $prereg->fresh()->only(['status', 'updated_at', 'expires_at']);
    $this->getJson('/enotf/schnittstelle/prereg')->assertOk()->assertJsonCount(1, 'preregistrations')
        ->assertJsonPath('preregistrations.0.id', $prereg->id)->assertJsonPath('preregistrations.0.note', 'V.a. Apoplex');
    $this->get('/enotf/schnittstelle')->assertOk()->assertInertia(fn ($page) => $page->component('Enotf/Portal/Board')->has('preregistrations', 1));
    expect($prereg->fresh()->only(['status', 'updated_at', 'expires_at']))->toEqual($before);

    $this->putJson("/enotf/schnittstelle/prereg/{$foreign->id}", ['status' => 'seen'])->assertNotFound();
    $this->putJson("/enotf/schnittstelle/prereg/{$prereg->id}", ['status' => 'arrived'])->assertOk()->assertJsonPath('status', 'arrived');
    $this->getJson('/enotf/schnittstelle/prereg')->assertJsonCount(0, 'preregistrations');
});

it('prunes expired clinic codes and pre-registrations older than 24 h', function () {
    $p = (new EnotfProtocol)->forceFill(['enr' => 'P-1']);
    $p->save();
    $poi = Poi::factory()->create(['type' => 'Klinik']);
    foreach ([now()->subMinute(), now()->addMinutes(30)] as $at) {
        (new ClinicCode)->forceFill(['enotf_protocol_id' => $p->id, 'code_hash' => 'x', 'expires_at' => $at])->save();
    }
    foreach ([now()->subHours(25), now()->subHours(2)] as $at) {
        (new Preregistration)->forceFill(['enotf_protocol_id' => $p->id, 'poi_id' => $poi->id, 'expires_at' => $at, 'created_at' => $at])->save();
    }

    $this->artisan('enotf:prune')->assertSuccessful();
    expect(ClinicCode::query()->pluck('expires_at')->all())->toHaveCount(1)->and(ClinicCode::query()->sole()->expires_at->isFuture())->toBeTrue()
        ->and(Preregistration::query()->sole()->created_at->gt(now()->subDay()))->toBeTrue();
});
