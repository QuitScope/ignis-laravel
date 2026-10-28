<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvLogEntry;
use Modules\ManvBoard\Models\ManvPatient;
use Modules\ManvBoard\Models\ManvResource;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function mvr_user(string ...$perms): User
{
    $role = Role::create(['name' => 'R'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function mvr_incident(array $over = []): ManvIncident
{
    $i = (new ManvIncident)->forceFill($over + ['incident_number' => 'L-'.uniqid(), 'location' => 'Halle', 'started_at' => now()]);
    $i->save();

    return $i;
}

function mvr_resource(ManvIncident $i, array $over = []): ManvResource
{
    $r = (new ManvResource)->forceFill($over + ['manv_incident_id' => $i->id, 'type' => 'vehicle', 'label' => 'RTW '.uniqid()]);
    $r->save();

    return $r;
}

function mvr_patient(ManvIncident $i, array $over = []): ManvPatient
{
    $p = (new ManvPatient)->forceFill($over + ['manv_incident_id' => $i->id, 'number' => 'MANV-'.uniqid()]);
    $p->save();

    return $p;
}

it('lists resources and offers only active stock vehicles that are not in the Lage yet, with search', function () {
    $i = mvr_incident();
    $in = Vehicle::factory()->create(['name' => 'Florian 1']);
    mvr_resource($i, ['label' => 'Florian 1', 'vehicle_id' => $in->id]);
    mvr_resource(mvr_incident(), ['label' => 'Fremd', 'vehicle_id' => ($other = Vehicle::factory()->create(['name' => 'Anders 2']))->id]);
    $free = Vehicle::factory()->create(['name' => 'Florian 3']);
    Vehicle::factory()->inactive()->create(['name' => 'Florian 4']);

    $this->actingAs(mvr_user('mci.manage'))->get("/mci/{$i->id}/resources")->assertInertia(fn ($p) => $p->component('ManvBoard/Resources')
        ->has('resources', 1)->where('can.write', true)
        ->where('stock', fn ($s) => collect($s)->pluck('id')->sort()->values()->all() === collect([$free->id, $other->id])->sort()->values()->all()));
    $this->get("/mci/{$i->id}/resources?search=Florian")->assertInertia(fn ($p) => $p
        ->where('stock', fn ($s) => collect($s)->pluck('id')->all() === [$free->id]));
});

it('lets viewers read the list without stock and without write access', function () {
    $i = mvr_incident();
    mvr_resource($i);
    Vehicle::factory()->create();

    $this->actingAs(mvr_user('mci.view'))->get("/mci/{$i->id}/resources")->assertInertia(fn ($p) => $p
        ->has('resources', 1)->where('stock', [])->where('can.write', false));
    $this->actingAs(mvr_user())->get("/mci/{$i->id}/resources")->assertForbidden();
    auth()->logout();
    $this->get("/mci/{$i->id}/resources")->assertRedirect();
});

it('creates a resource from the stock with label, callsign and type taken from the vehicle', function () {
    $i = mvr_incident();
    $v = Vehicle::factory()->create(['name' => 'Florian 5/83', 'identifier' => 'RK-583', 'type' => 'RTW']);
    $m = mvr_user('mci.manage');

    $this->actingAs($m)->post("/mci/{$i->id}/resources", ['vehicle_id' => $v->id, 'label' => 'Fälschung', 'type' => 'material', 'status' => 'deployed', 'location' => 'Zufahrt'])->assertRedirect();

    $r = ManvResource::sole();
    expect($r->label)->toBe('Florian 5/83')->and($r->callsign)->toBe('RK-583')->and($r->vehicle_type)->toBe('RTW')->and($r->vehicle_id)->toBe($v->id)
        ->and($r->type->value)->toBe('vehicle')->and($r->status->value)->toBe('deployed')->and($r->location)->toBe('Zufahrt')->and($r->manv_incident_id)->toBe($i->id);
    $log = ManvLogEntry::sole();
    expect($log->action)->toBe('ressource_erstellt')->and($log->subject_type)->toBe('resource')->and($log->subject_id)->toBe($r->id)->and($log->user_id)->toBe($m->id);
    expect(Activity::where('log_name', 'MANV')->latest('id')->first()->details)->toContain('Florian 5/83');
});

it('rejects a stock vehicle twice, inactive, unknown and a label clash', function () {
    $i = mvr_incident();
    $v = Vehicle::factory()->create(['name' => 'Florian 1']);
    $this->actingAs(mvr_user('mci.manage'));

    $this->post("/mci/{$i->id}/resources", ['vehicle_id' => $v->id])->assertRedirect();
    $this->postJson("/mci/{$i->id}/resources", ['vehicle_id' => $v->id])->assertStatus(422)->assertJsonValidationErrors('vehicle_id');
    $this->postJson("/mci/{$i->id}/resources", ['vehicle_id' => Vehicle::factory()->inactive()->create()->id])->assertStatus(422)->assertJsonValidationErrors('vehicle_id');
    $this->postJson("/mci/{$i->id}/resources", ['vehicle_id' => 99999])->assertStatus(422)->assertJsonValidationErrors('vehicle_id');
    $w = Vehicle::factory()->create(['name' => 'Frei']);
    $this->post("/mci/{$i->id}/resources", ['type' => 'vehicle', 'label' => 'Frei'])->assertRedirect();
    $this->postJson("/mci/{$i->id}/resources", ['vehicle_id' => $w->id])->assertStatus(422)->assertJsonValidationErrors('label');
    expect(ManvResource::count())->toBe(2);
});

it('creates a free resource ("Schnell anlegen") and requires label and type', function () {
    $i = mvr_incident();
    $this->actingAs(mvr_user('mci.manage'));

    $this->post("/mci/{$i->id}/resources", ['type' => 'personnel', 'label' => 'Trupp 1', 'crew' => '1/3', 'notes' => 'Sichtung'])->assertRedirect();
    $r = ManvResource::sole();
    expect($r->type->value)->toBe('personnel')->and($r->status->value)->toBe('available')->and($r->vehicle_id)->toBeNull()->and($r->crew)->toBe('1/3');

    $this->postJson("/mci/{$i->id}/resources", ['type' => 'vehicle'])->assertStatus(422)->assertJsonValidationErrors('label');
    $this->postJson("/mci/{$i->id}/resources", ['label' => 'X'])->assertStatus(422)->assertJsonValidationErrors('type');
    $this->postJson("/mci/{$i->id}/resources", ['label' => 'X', 'type' => 'boat'])->assertStatus(422)->assertJsonValidationErrors('type');
    $this->postJson("/mci/{$i->id}/resources", ['label' => 'Y', 'type' => 'vehicle', 'status' => 'gone'])->assertStatus(422)->assertJsonValidationErrors('status');
    $this->postJson("/mci/{$i->id}/resources", ['label' => str_repeat('x', 256), 'type' => 'vehicle'])->assertStatus(422)->assertJsonValidationErrors('label');
    expect(ManvResource::count())->toBe(1);
});

it('keeps the label unique per Lage only', function () {
    $i = mvr_incident();
    mvr_resource($i, ['label' => 'RTW 1']);
    $this->actingAs(mvr_user('mci.manage'));

    $this->postJson("/mci/{$i->id}/resources", ['type' => 'vehicle', 'label' => 'RTW 1'])->assertStatus(422)->assertJsonValidationErrors('label');
    $this->post('/mci/'.mvr_incident()->id.'/resources', ['type' => 'vehicle', 'label' => 'RTW 1'])->assertRedirect();
    expect(ManvResource::where('label', 'RTW 1')->count())->toBe(2);
});

it('updates all fields including the status and logs it', function () {
    $i = mvr_incident();
    $r = mvr_resource($i, ['label' => 'RTW 1', 'status' => 'available']);
    $m = mvr_user('mci.manage');

    $this->actingAs($m)->put("/mci/{$i->id}/resources/{$r->id}", [
        'label' => 'RTW 1a', 'callsign' => 'Rettung 1', 'vehicle_type' => 'RTW', 'status' => 'unavailable', 'crew' => '2/0', 'location' => 'Sammelplatz', 'notes' => 'Defekt', 'type' => 'material', 'vehicle_id' => 5,
    ])->assertRedirect();

    $r->refresh();
    expect($r->label)->toBe('RTW 1a')->and($r->callsign)->toBe('Rettung 1')->and($r->status->value)->toBe('unavailable')->and($r->crew)->toBe('2/0')
        ->and($r->location)->toBe('Sammelplatz')->and($r->notes)->toBe('Defekt')->and($r->type->value)->toBe('vehicle')->and($r->vehicle_id)->toBeNull();
    expect(ManvLogEntry::sole()->action)->toBe('ressource_aktualisiert');
    $this->putJson("/mci/{$i->id}/resources/{$r->id}", ['label' => 'RTW 1a', 'status' => 'gone'])->assertStatus(422)->assertJsonValidationErrors('status');
});

it('keeps the status when update omits it and rejects a clashing or empty label', function () {
    $i = mvr_incident();
    mvr_resource($i, ['label' => 'RTW 2']);
    $r = mvr_resource($i, ['label' => 'RTW 1', 'status' => 'deployed']);
    $this->actingAs(mvr_user('mci.manage'));

    $this->put("/mci/{$i->id}/resources/{$r->id}", ['label' => 'RTW 1'])->assertRedirect();
    expect($r->refresh()->status->value)->toBe('deployed');
    $this->putJson("/mci/{$i->id}/resources/{$r->id}", ['label' => 'RTW 2'])->assertStatus(422)->assertJsonValidationErrors('label');
    $this->putJson("/mci/{$i->id}/resources/{$r->id}", ['label' => ''])->assertStatus(422)->assertJsonValidationErrors('label');
});

it('does not break the patient assignment when the resource is renamed', function () {
    $i = mvr_incident();
    $r = mvr_resource($i, ['label' => 'RTW 1']);
    $p = mvr_patient($i, ['manv_resource_id' => $r->id, 'callsign' => 'RTW 1']);

    $this->actingAs(mvr_user('mci.manage'))->put("/mci/{$i->id}/resources/{$r->id}", ['label' => 'RTW Neu'])->assertRedirect();
    expect($p->refresh()->manv_resource_id)->toBe($r->id)->and($p->resource->label)->toBe('RTW Neu');
});

it('keeps the label of a departed resource but allows the other fields', function () {
    $i = mvr_incident();
    $r = mvr_resource($i, ['label' => 'RTW 1', 'departed_at' => now()]);
    $this->actingAs(mvr_user('mci.manage'));

    $this->putJson("/mci/{$i->id}/resources/{$r->id}", ['label' => 'Anders'])->assertStatus(422)->assertJsonValidationErrors('label');
    $this->put("/mci/{$i->id}/resources/{$r->id}", ['label' => 'RTW 1', 'notes' => 'zurück'])->assertRedirect();
    expect($r->refresh()->notes)->toBe('zurück')->and($r->label)->toBe('RTW 1');
});

it('deletes a resource, logs it and keeps departed patients with their snapshot', function () {
    $i = mvr_incident();
    $r = mvr_resource($i, ['label' => 'RTW 1']);
    $gone = mvr_patient($i, ['manv_resource_id' => $r->id, 'callsign' => 'RTW 1', 'departed_at' => now()]);
    $m = mvr_user('mci.manage');

    $this->actingAs($m)->delete("/mci/{$i->id}/resources/{$r->id}")->assertRedirect();
    expect(ManvResource::count())->toBe(0)->and($gone->refresh()->manv_resource_id)->toBeNull()->and($gone->callsign)->toBe('RTW 1');
    expect(ManvLogEntry::sole()->action)->toBe('ressource_geloescht');
    expect(Activity::where('log_name', 'MANV')->latest('id')->first()->details)->toContain('RTW 1');
});

it('refuses to delete a resource with an un-departed patient', function () {
    $i = mvr_incident();
    $r = mvr_resource($i, ['label' => 'RTW 1']);
    mvr_patient($i, ['number' => 'MANV-007', 'manv_resource_id' => $r->id]);

    $this->actingAs(mvr_user('mci.manage'))->deleteJson("/mci/{$i->id}/resources/{$r->id}")->assertStatus(422)->assertJsonValidationErrors('delete');
    expect(ManvResource::count())->toBe(1)->and(ManvLogEntry::count())->toBe(0);
});

it('answers 404 for a resource of another Lage on update and delete', function () {
    $i = mvr_incident();
    $foreign = mvr_resource(mvr_incident(), ['label' => 'Fremd']);
    $this->actingAs(mvr_user('mci.manage'));

    $this->put("/mci/{$i->id}/resources/{$foreign->id}", ['label' => 'Neu'])->assertNotFound();
    $this->delete("/mci/{$i->id}/resources/{$foreign->id}")->assertNotFound();
    expect($foreign->refresh()->label)->toBe('Fremd');
});

it('allows writes only to managers on an active Lage', function () {
    $i = mvr_incident();
    $r = mvr_resource($i, ['label' => 'RTW 1']);
    $this->actingAs(mvr_user('mci.view'));
    $this->post("/mci/{$i->id}/resources", ['type' => 'vehicle', 'label' => 'N'])->assertForbidden();
    $this->put("/mci/{$i->id}/resources/{$r->id}", ['label' => 'N'])->assertForbidden();
    $this->delete("/mci/{$i->id}/resources/{$r->id}")->assertForbidden();

    foreach (['closed', 'archived'] as $status) {
        $c = mvr_incident(['status' => $status]);
        $cr = mvr_resource($c, ['label' => 'RTW 1']);
        foreach ([mvr_user('mci.manage'), mvr_user('admin')] as $u) {
            $this->actingAs($u);
            $this->post("/mci/{$c->id}/resources", ['type' => 'vehicle', 'label' => 'N'])->assertForbidden();
            $this->put("/mci/{$c->id}/resources/{$cr->id}", ['label' => 'N'])->assertForbidden();
            $this->delete("/mci/{$c->id}/resources/{$cr->id}")->assertForbidden();
        }
        $this->get("/mci/{$c->id}/resources")->assertInertia(fn ($p) => $p->where('can.write', false)->where('stock', []));
        expect($cr->refresh()->label)->toBe('RTW 1')->and(ManvResource::where('manv_incident_id', $c->id)->count())->toBe(1);
    }
    expect($r->refresh()->label)->toBe('RTW 1')->and(ManvLogEntry::count())->toBe(0);
});

it('shows resource names verbatim and never as html', function () {
    expect(file_get_contents(resource_path('js/Pages/ManvBoard/Resources.vue')))->not->toContain('v-html');
});
