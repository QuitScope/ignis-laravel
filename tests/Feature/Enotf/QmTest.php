<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InboxNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\ProtocolCrew;
use Modules\Enotf\Models\ProtocolReviewLog;

uses(RefreshDatabase::class);

function enotfQmUser(string ...$perms): User
{
    test()->seed(RoleSeeder::class);
    $role = Role::create(['name' => 'R'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create(['personnel_id' => Personnel::factory()->create()->id]);
}

function qm_protocol(array $over = []): array
{
    $p = (new EnotfProtocol)->forceFill($over + ['enr' => (string) random_int(100000, 999999), 'status' => 'released', 'released_at' => now(), 'patient_last_name' => 'Geheim']);
    $p->save();
    $crew = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    (new ProtocolCrew)->forceFill(['enotf_protocol_id' => $p->id, 'slot' => 'transport', 'position' => 1, 'personnel_id' => $crew->personnel_id, 'name' => 'X'])->save();

    return [$p, $crew];
}

it('lets edivi.view and super-admin only read; crew without a right and guests get nothing', function () {
    [$p] = qm_protocol();
    $url = "/enotf/qm/{$p->enr}";

    $this->get($url)->assertRedirect('/login');
    $this->actingAs(enotfQmUser('enotf.view'))->get($url)->assertForbidden();
    $this->actingAs(enotfQmUser('enotf.view'))->patchJson("$url/status", ['qm_status' => 2])->assertForbidden();

    foreach ([enotfQmUser('edivi.view'), User::factory()->superAdmin()->create()] as $reader) {
        $this->actingAs($reader)->get($url)->assertOk()->assertInertia(fn ($page) => $page->component('Enotf/Qm/Show')
            ->where('can.review', false)->where('protocol.enr', $p->enr)->missing('protocol.patient_last_name'));
        $this->actingAs($reader)->patchJson("$url/status", ['qm_status' => 2])->assertForbidden();
        $this->actingAs($reader)->postJson("$url/comments", ['text' => 'x'])->assertForbidden();
        $this->actingAs($reader)->postJson("$url/reopen")->assertForbidden();
    }
    expect(ProtocolReviewLog::count())->toBe(0)->and($p->fresh()->status->value)->toBe('released');
});

it('changes the QM status and comments with log entries, bell to the crew and audit without patient data', function () {
    Notification::fake();
    [$p, $crew] = qm_protocol();
    $qm = enotfQmUser('edivi.edit');
    $url = "/enotf/qm/{$p->enr}";

    $this->actingAs($qm)->patchJson("$url/status", ['qm_status' => 2])->assertOk()->assertJson(['qm_status' => 2]);
    $this->actingAs($qm)->patchJson("$url/status", ['qm_status' => 2])->assertOk();
    $this->actingAs($qm)->patchJson("$url/status", ['qm_status' => 5])->assertUnprocessable();
    $this->actingAs($qm)->postJson("$url/comments", ['text' => '<b>Bitte</b> ergänzen'])->assertCreated();
    $this->actingAs($qm)->postJson("$url/comments", ['text' => str_repeat('x', 2001)])->assertUnprocessable();
    $this->actingAs($qm)->postJson("$url/comments", ['text' => '   '])->assertUnprocessable();

    expect($p->fresh()->qm_status->value)->toBe(2)
        ->and(ProtocolReviewLog::orderBy('id')->get(['kind', 'from_status', 'to_status', 'text', 'actor_user_id'])->toArray())->toBe([
            ['kind' => 'status', 'from_status' => 0, 'to_status' => 2, 'text' => null, 'actor_user_id' => $qm->id],
            ['kind' => 'comment', 'from_status' => null, 'to_status' => null, 'text' => '<b>Bitte</b> ergänzen', 'actor_user_id' => $qm->id],
        ]);
    Notification::assertSentToTimes($crew, InboxNotification::class, 1);
    Notification::assertNotSentTo($qm, InboxNotification::class);
    $this->assertDatabaseHas('activity_log', ['description' => 'QM-Status geändert', 'details' => $p->enr]);
    $this->actingAs($qm)->get($url)->assertInertia(fn ($page) => $page->where('can.review', true)->has('log', 2)->where('log.0.kind', 'comment'));
});

it('reopens a released protocol once (409 on repeat) and never a billed one', function () {
    Notification::fake();
    [$p, $crew] = qm_protocol(['revision' => 3]);
    [$billed] = qm_protocol(['billed_at' => now()]);
    $qm = enotfQmUser('edivi.edit');

    $this->actingAs($qm)->postJson("/enotf/qm/{$p->enr}/reopen")->assertOk()->assertJson(['status' => 'open', 'revision' => 4]);
    $this->actingAs($qm)->postJson("/enotf/qm/{$p->enr}/reopen")->assertStatus(409);
    $this->actingAs($qm)->postJson("/enotf/qm/{$billed->enr}/reopen")->assertStatus(409);
    [$hidden] = qm_protocol(['hidden_at' => now()]);
    $this->actingAs($qm)->postJson("/enotf/qm/{$hidden->enr}/reopen")->assertStatus(409);

    $p->refresh();
    expect($p->status->value)->toBe('open')->and($p->released_at)->toBeNull()
        ->and($billed->fresh()->status->value)->toBe('released')
        ->and(ProtocolReviewLog::pluck('kind')->all())->toBe(['reopen']);
    Notification::assertSentToTimes($crew, InboxNotification::class, 1);
});
