<?php

use App\Models\Personnel;
use App\Models\Rank;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\ManvBoard\Enums\ManvIncidentStatus;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvLogEntry;
use Modules\ManvBoard\Models\ManvPatient;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function ic_user(string ...$perms): User
{
    $role = Role::create(['name' => 'I'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function ic_incident(array $over = []): ManvIncident
{
    $i = (new ManvIncident)->forceFill($over + ['incident_number' => 'L-'.uniqid(), 'location' => 'Halle', 'started_at' => now()]);
    $i->save();

    return $i;
}

function ic_patient(ManvIncident $i, ?string $triage, bool $departed = false): void
{
    (new ManvPatient)->forceFill(['manv_incident_id' => $i->id, 'number' => uniqid(), 'triage' => $triage, 'departed_at' => $departed ? now() : null])->save();
}

function ic_body(array $over = []): array
{
    return $over + ['incident_number' => 'MANV-7', 'location' => 'Autobahn A3', 'reason' => 'Busunfall'];
}

it('lists active Lagen by default and filters by status', function () {
    ic_incident(['incident_number' => 'A']);
    ic_incident(['incident_number' => 'C', 'status' => 'closed']);
    $this->actingAs(ic_user('mci.view'));

    $this->get('/mci')->assertInertia(fn ($p) => $p->component('ManvBoard/Index')
        ->where('incidents.data', fn ($d) => collect($d)->pluck('incident_number')->all() === ['A'])
        ->where('can.manage', false));
    $this->get('/mci?status=closed')->assertInertia(fn ($p) => $p->where('incidents.data.0.incident_number', 'C'));
    $this->get('/mci?status=all')->assertInertia(fn ($p) => $p->has('incidents.data', 2));
    $this->get('/mci?status=bogus')->assertSessionHasErrors('status');
});

it('paginates the list', function () {
    foreach (range(1, 30) as $n) {
        ic_incident();
    }
    $this->actingAs(ic_user('mci.view'))->get('/mci')->assertInertia(fn ($p) => $p->has('incidents.data', 25)->where('incidents.total', 30));
});

it('computes the counters per Lage and keeps the query count constant', function () {
    $a = ic_incident();
    ic_patient($a, 'SK1');
    ic_patient($a, 'SK1', true);
    ic_patient($a, 'SK3');
    ic_patient($a, null);
    $this->actingAs(ic_user('mci.view'))->get('/mci')->assertInertia(fn ($p) => $p
        ->where('incidents.data.0.counts.total', 4)
        ->where('incidents.data.0.counts.waiting', 3)
        ->where('incidents.data.0.counts.departed', 1)
        ->where('incidents.data.0.counts.triage.SK1', 2)
        ->where('incidents.data.0.counts.triage.SK3', 1));

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/mci')->assertOk();

        return count(DB::getQueryLog());
    };
    $few = $count();
    foreach (range(1, 20) as $n) {
        ic_patient(ic_incident(), 'SK2');
    }
    expect($count())->toBe($few);
});

it('shows the create page and the personnel search only to managers', function () {
    $this->actingAs(ic_user('mci.view'));
    $this->get('/mci/create')->assertForbidden();
    $this->getJson('/mci/personnel?q=a')->assertForbidden();
    $this->actingAs(ic_user('mci.manage'))->get('/mci/create')->assertOk()->assertInertia(fn ($p) => $p->component('ManvBoard/Create'));
});

it('searches active personnel (max 20, by name or service number)', function () {
    Personnel::factory()->create(['full_name' => 'Anna Notarzt', 'service_number' => 'XQ-9']);
    Personnel::factory()->create(['full_name' => 'Archiv Person', 'rank_id' => Rank::factory()->create(['is_archive' => true])->id]);
    Personnel::factory()->count(25)->create(['full_name' => 'Zed Sample']);
    $this->actingAs(ic_user('mci.manage'));

    $this->getJson('/mci/personnel?q=Notarzt')->assertOk()->assertJsonCount(1)->assertJsonPath('0.full_name', 'Anna Notarzt')->assertJsonPath('0.service_number', 'XQ-9');
    $this->getJson('/mci/personnel?q=XQ-9')->assertJsonCount(1);
    $this->getJson('/mci/personnel?q=Archiv')->assertJsonCount(0);
    $this->getJson('/mci/personnel?q=Zed')->assertJsonCount(20);
});

it('creates a Lage with server-set snapshot, creator, audit and log', function () {
    $lna = Personnel::factory()->create(['full_name' => 'Anna Notarzt']);
    $manager = ic_user('mci.manage');

    $this->actingAs($manager)->post('/mci', ic_body(['lna_personnel_id' => $lna->id, 'lna_name' => 'Fälschung', 'created_by' => 999, 'status' => 'closed']))
        ->assertRedirect();

    $i = ManvIncident::sole();
    expect($i->lna_name)->toBe('Anna Notarzt')->and($i->lna_personnel_id)->toBe($lna->id)->and($i->orgl_name)->toBeNull()
        ->and($i->status)->toBe(ManvIncidentStatus::Active)->and($i->created_by)->toBe($manager->id)->and($i->started_at)->not->toBeNull();
    $log = ManvLogEntry::sole();
    expect($log->action)->toBe('lage_erstellt')->and($log->user_id)->toBe($manager->id)->and($log->user_name)->toBe($manager->name);
    expect(Activity::where('log_name', 'MANV')->count())->toBe(1);
});

it('validates the create request', function () {
    $this->actingAs(ic_user('mci.manage'));
    $this->post('/mci', ic_body(['incident_number' => '']))->assertSessionHasErrors('incident_number');
    $this->post('/mci', ic_body(['incident_number' => str_repeat('x', 51)]))->assertSessionHasErrors('incident_number');
    $this->post('/mci', ic_body(['location' => str_repeat('x', 256)]))->assertSessionHasErrors('location');
    $this->post('/mci', ic_body(['lna_personnel_id' => 9999]))->assertSessionHasErrors('lna_personnel_id');
    $archived = Personnel::factory()->create(['rank_id' => Rank::factory()->create(['is_archive' => true])->id]);
    $this->post('/mci', ic_body(['orgl_personnel_id' => $archived->id]))->assertSessionHasErrors('orgl_personnel_id');
    expect(ManvIncident::count())->toBe(0);
});

it('denies writes to viewers and guests', function () {
    $i = ic_incident();
    $this->post('/mci', ic_body())->assertRedirect('/login');
    $this->actingAs(ic_user('mci.view'));
    $this->post('/mci', ic_body())->assertForbidden();
    $this->get("/mci/{$i->id}/edit")->assertForbidden();
    $this->put("/mci/{$i->id}", ic_body())->assertForbidden();
});

it('edits an active Lage, keeps the start when none is sent and refreshes the snapshot', function () {
    $start = now()->subHour()->startOfSecond();
    $i = ic_incident(['started_at' => $start]);
    $orgl = Personnel::factory()->create(['full_name' => 'Olga Leiter']);
    $m = ic_user('mci.manage');

    $this->actingAs($m)->get("/mci/{$i->id}/edit")->assertInertia(fn ($p) => $p->component('ManvBoard/Edit')->where('incident.id', $i->id));
    $this->put("/mci/{$i->id}", ic_body(['started_at' => null, 'orgl_personnel_id' => $orgl->id, 'orgl_name' => 'x', 'status' => 'active']))->assertRedirect();

    $i->refresh();
    expect($i->started_at->equalTo($start))->toBeTrue()->and($i->orgl_name)->toBe('Olga Leiter')->and($i->updated_by)->toBe($m->id)
        ->and($i->location)->toBe('Autobahn A3');
    expect(ManvLogEntry::where('action', 'lage_bearbeitet')->count())->toBe(1);

    $this->put("/mci/{$i->id}", ic_body(['started_at' => '2026-10-21T08:30', 'orgl_personnel_id' => null, 'status' => 'active']));
    $i->refresh();
    expect($i->orgl_name)->toBeNull()->and($i->orgl_personnel_id)->toBeNull()
        ->and($i->started_at->format('Y-m-d H:i'))->toBe('2026-10-21 06:30');
});

it('rejects an unknown status instead of silently activating', function () {
    $i = ic_incident(['status' => 'closed']);
    $this->actingAs(ic_user('mci.manage'))->put("/mci/{$i->id}", ic_body(['status' => 'weird']))->assertSessionHasErrors('status');
    expect($i->refresh()->status)->toBe(ManvIncidentStatus::Closed);
});

it('sets and clears ended_at on status changes', function () {
    $i = ic_incident();
    $this->actingAs(ic_user('mci.manage'));

    $this->put("/mci/{$i->id}", ic_body(['status' => 'closed']))->assertRedirect();
    $i->refresh();
    expect($i->status)->toBe(ManvIncidentStatus::Closed)->and($i->ended_at)->not->toBeNull();
    expect(ManvLogEntry::where('action', 'lage_abgeschlossen')->count())->toBe(1);

    $this->put("/mci/{$i->id}", ['status' => 'archived'])->assertRedirect();
    expect($i->refresh()->status)->toBe(ManvIncidentStatus::Archived)->and($i->ended_at)->not->toBeNull();

    $this->put("/mci/{$i->id}", ['status' => 'active'])->assertRedirect();
    $i->refresh();
    expect($i->status)->toBe(ManvIncidentStatus::Active)->and($i->ended_at)->toBeNull();
    expect(ManvLogEntry::where('action', 'lage_reaktiviert')->count())->toBe(1);
});

it('allows only the status on a closed or archived Lage', function () {
    foreach (['closed', 'archived'] as $status) {
        $i = ic_incident(['status' => $status, 'location' => 'Alt']);
        $this->actingAs(ic_user('mci.manage'));
        $this->put("/mci/{$i->id}", ic_body(['location' => 'Neu', 'status' => $status]))->assertRedirect();
        expect($i->refresh()->location)->toBe('Alt');
    }
});

it('marks a closed Lage as not writable on the edit page', function () {
    $i = ic_incident(['status' => 'closed']);
    $this->actingAs(ic_user('mci.manage'))->get("/mci/{$i->id}/edit")->assertInertia(fn ($p) => $p->where('incident.writable', false));
});

it('keeps saving and closing a Lage whose LNA was archived later, but rejects switching to an inactive file', function () {
    $lna = Personnel::factory()->create(['full_name' => 'Anna Notarzt']);
    $i = ic_incident(['lna_personnel_id' => $lna->id, 'lna_name' => 'Anna Notarzt']);
    $lna->update(['rank_id' => Rank::factory()->create(['is_archive' => true])->id]);
    $lna->update(['full_name' => 'Anna Umbenannt']);
    $this->actingAs(ic_user('mci.manage'));

    $this->put("/mci/{$i->id}", ic_body(['lna_personnel_id' => $lna->id]))->assertSessionHasNoErrors();
    expect($i->refresh()->lna_name)->toBe('Anna Notarzt')->and($i->location)->toBe('Autobahn A3');
    $this->put("/mci/{$i->id}", ic_body(['lna_personnel_id' => $lna->id, 'status' => 'closed']))->assertSessionHasNoErrors();
    expect($i->refresh()->status)->toBe(ManvIncidentStatus::Closed);

    $other = Personnel::factory()->create(['rank_id' => Rank::factory()->create(['is_archive' => true])->id]);
    $j = ic_incident();
    $this->put("/mci/{$j->id}", ic_body(['orgl_personnel_id' => $other->id]))->assertSessionHasErrors('orgl_personnel_id');
});
