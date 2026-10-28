<?php

use App\Models\Personnel;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\InboxNotification;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\MedicationGiven;
use Modules\Enotf\Models\ShareRequest;
use Modules\Enotf\Models\VitalSign;

uses(RefreshDatabase::class)->group('mariadb');

function shCrew(): array
{
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS]);
    $user = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    test()->actingAs($user)->post('/enotf/login', ['vehicle_id' => $vehicle->id, 'pin' => '1234'])->assertRedirect('/enotf/overview');

    return [$user, $vehicle, session('crew.enotf')];
}

function shAs(array $crew): void
{
    test()->actingAs($crew[0])->withSession(['crew.enotf' => $crew[2]]);
}

function shProtocol(Vehicle $vehicle, string $enr, array $data = [], array $columns = []): EnotfProtocol
{
    $p = (new EnotfProtocol)->forceFill(['enr' => $enr, 'vehicle_transport_id' => $vehicle->id, 'data' => $data] + $columns);
    $p->save();

    return $p->fresh();
}

function shScenario(): array
{
    $a = shCrew();
    $b = shCrew();
    $source = shProtocol($a[1], '100', ['diagnose' => 'Sturz', 'anmerkungen' => 'Notiz', 's1' => '2026-10-23T10:00:00Z', 'fzg_sonst' => 'RTW 2', 'medis_keine' => true],
        ['patient_last_name' => 'Muster']);
    $target = shProtocol($b[1], '200', ['diagnose' => 'Eigene']);
    shAs($a);
    $id = test()->postJson("/enotf/p/{$source->enr}/share", ['to_vehicle_id' => $b[1]->id])->assertCreated()->json('id');

    return [$a, $b, $source, $target, ShareRequest::query()->findOrFail($id)];
}

it('copies only allowlisted fields, fills only empty target fields through the revisioned merge, notifies once', function () {
    Notification::fake();
    [$a, $b, , $target, $share] = shScenario();

    Notification::assertSentToTimes($b[0], InboxNotification::class, 1);
    Notification::assertNotSentTo($a[0], InboxNotification::class);
    expect(array_keys($share->payload))->toEqualCanonicalizing(['pat_nachname', 'diagnose', 'anmerkungen']);

    shAs($b);
    $this->getJson('/enotf/status')->assertJsonPath('shares.0.id', $share->id)->assertJsonPath('shares.0.enr', '100');
    $this->postJson("/enotf/share/{$share->id}/accept", ['target_enr' => '200'])->assertOk()
        ->assertJsonPath('enr', '200')->assertJsonPath('kept', ['diagnose']);
    $t = $target->fresh();
    expect($t->data['diagnose'])->toBe('Eigene')->and($t->patient_last_name)->toBe('Muster')->and($t->data['anmerkungen'])->toBe('Notiz')
        ->and($t->data)->not->toHaveKey('s1')->and($t->revision)->toBe(2)->and($t->field_revisions)->toBe(['pat_nachname' => 2, 'anmerkungen' => 2])
        ->and($share->fresh()->status)->toBe('accepted')->and($share->fresh()->target_protocol_id)->toBe($t->id);

    $this->postJson("/enotf/share/{$share->id}/accept", ['target_enr' => '200'])->assertStatus(409);
    expect($target->fresh()->revision)->toBe(2);

    shAs($a);
    $second = $this->postJson('/enotf/p/100/share', ['to_vehicle_id' => $b[1]->id])->assertCreated()->json('id');
    shAs($b);
    $this->postJson("/enotf/share/{$second}/accept")->assertOk()->assertJsonPath('enr', '100_1');
    expect(EnotfProtocol::query()->where('enr', '100_1')->sole())->vehicle_transport_id->toBe($b[1]->id)->patient_last_name->toBe('Muster');
});

it('keeps no patient data after an answer, refuses a duplicate pending offer and deletes old resolved requests', function () {
    [$a, $b, $source, , $share] = shScenario();
    $this->postJson("/enotf/p/{$source->enr}/share", ['to_vehicle_id' => $b[1]->id])->assertStatus(409);
    shAs($b);
    $this->postJson("/enotf/share/{$share->id}/accept", ['target_enr' => '200'])->assertOk();
    expect($share->fresh()->payload)->toBe([]);

    shAs($a);
    $second = $this->postJson("/enotf/p/{$source->enr}/share", ['to_vehicle_id' => $b[1]->id])->assertCreated()->json('id');
    shAs($b);
    $this->postJson("/enotf/share/{$second}/reject")->assertOk();
    expect(ShareRequest::query()->findOrFail($second)->payload)->toBe([]);

    ShareRequest::query()->whereKey($share->id)->update(['resolved_at' => now()->subDays(2)]);
    $this->artisan('enotf:share-expire')->assertSuccessful();
    expect(ShareRequest::query()->pluck('id')->all())->toBe([$second]);
});

it('lets exactly one of two concurrent accepts win (conditional UPDATE, no check-then-act)', function () {
    [, $b, , $target, $share] = shScenario();
    shAs($b);
    $done = false;
    DB::listen(function (QueryExecuted $q) use (&$done, $share) {
        if (! $done && str_starts_with($q->sql, 'select') && str_contains($q->sql, 'enotf_share_requests')) {
            $done = true;
            DB::table('enotf_share_requests')->where('id', $share->id)->update(['status' => 'accepted']);
        }
    });
    $this->postJson("/enotf/share/{$share->id}/accept", ['target_enr' => '200'])->assertStatus(409);
    expect($done)->toBeTrue()->and($target->fresh()->revision)->toBe(1)->and($target->fresh()->patient_last_name)->toBeNull()
        ->and(EnotfProtocol::query()->count())->toBe(2);
});

it('blocks accepting after 30 minutes and marks expired requests', function () {
    [, $b, , , $share] = shScenario();
    expect($share->expires_at->diffInMinutes(now(), true))->toBeGreaterThan(29.9)->toBeLessThan(30.1);
    ShareRequest::query()->whereKey($share->id)->update(['expires_at' => now()->subSecond()]);
    shAs($b);
    $this->getJson('/enotf/status')->assertJsonPath('shares', []);
    $this->postJson("/enotf/share/{$share->id}/accept", ['target_enr' => '200'])->assertStatus(409);
    $this->artisan('enotf:share-expire')->assertSuccessful();
    expect($share->fresh()->status)->toBe('expired')->and($share->fresh()->payload)->toBe([]);
});

it('refuses merging into a foreign or closed protocol and keeps the request pending', function () {
    [, $b, , $target, $share] = shScenario();
    shAs($b);
    $this->postJson("/enotf/share/{$share->id}/accept", ['target_enr' => '100'])->assertForbidden();
    EnotfProtocol::query()->whereKey($target->id)->update(['status' => 'released']);
    $this->postJson("/enotf/share/{$share->id}/accept", ['target_enr' => '200'])->assertStatus(409);
    expect($share->fresh()->status)->toBe('pending');
});

it('guards request ids: only the target crew answers, only the sender cancels, never the sender accepts', function () {
    [$a, $b, $source, , $share] = shScenario();
    $c = shCrew();

    $this->postJson("/enotf/p/{$source->enr}/share", ['to_vehicle_id' => $b[1]->id])->assertForbidden();
    $this->postJson("/enotf/share/{$share->id}/accept")->assertForbidden();
    $this->postJson("/enotf/share/{$share->id}/reject")->assertForbidden();
    $this->postJson("/enotf/share/{$share->id}/cancel")->assertForbidden();
    shAs($b);
    $this->postJson("/enotf/share/{$share->id}/cancel")->assertForbidden();
    ShareRequest::query()->whereKey($share->id)->update(['requested_by_user_id' => $b[0]->id]);
    $this->postJson("/enotf/share/{$share->id}/accept")->assertForbidden();
    ShareRequest::query()->whereKey($share->id)->update(['requested_by_user_id' => $a[0]->id]);

    shAs($a);
    $this->postJson("/enotf/share/{$share->id}/cancel")->assertOk();
    shAs($b);
    $this->postJson("/enotf/share/{$share->id}/reject")->assertStatus(409);
    expect($share->fresh()->status)->toBe('cancelled');
});

it('copies vital and medication rows on accept (a repeated dose twice), keeps the target rows and adds no duplicates on a second share', function () {
    [$a, $b, $source, $target, $share] = shScenario();
    $vital = fn (EnotfProtocol $p, string $param, string $value) => (new VitalSign)->forceFill(['enotf_protocol_id' => $p->id, 'parameter' => $param, 'value' => $value, 'unit' => 'mmHg', 'measured_at' => '2026-10-23 10:00:00'])->save();
    $vital($source, 'rrsys', '120');
    $vital($target, 'hf', '80');
    (new VitalSign)->forceFill(['enotf_protocol_id' => $source->id, 'parameter' => 'spo2', 'value' => '90', 'measured_at' => '2026-10-23 10:00:00', 'deleted_at' => now()])->save();
    (new MedicationGiven)->forceFill(['enotf_protocol_id' => $source->id, 'name' => 'Paracetamol', 'dose' => '1', 'unit' => 'g', 'route' => 'i.v.', 'given_at' => '2026-10-23 10:05:00'])->save();
    foreach ([1, 2] as $_) {
        (new MedicationGiven)->forceFill(['enotf_protocol_id' => $source->id, 'name' => 'Adrenalin', 'dose' => '1', 'unit' => 'mg', 'route' => 'i.v.', 'given_at' => '2026-10-23 10:10:00'])->save();
    }

    shAs($b);
    $this->postJson("/enotf/share/{$share->id}/accept", ['target_enr' => '200'])->assertOk();
    shAs($a);
    $second = $this->postJson("/enotf/p/{$source->enr}/share", ['to_vehicle_id' => $b[1]->id])->assertCreated()->json('id');
    shAs($b);
    $this->postJson("/enotf/share/{$second}/accept", ['target_enr' => '200'])->assertOk();

    expect(VitalSign::query()->where('enotf_protocol_id', $target->id)->orderBy('parameter')->pluck('parameter')->all())->toBe(['hf', 'rrsys'])
        ->and(MedicationGiven::query()->where('enotf_protocol_id', $target->id)->orderBy('name')->pluck('name')->all())->toBe(['Adrenalin', 'Adrenalin', 'Paracetamol'])
        ->and(VitalSign::query()->where('enotf_protocol_id', $source->id)->count())->toBe(2);
});
