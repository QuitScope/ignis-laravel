<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Models\EnotfCategory;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\EnotfQuicklink;
use Modules\Enotf\Models\ProtocolCrew;
use Modules\Enotf\Support\CrewContext;

uses(RefreshDatabase::class);

function en_protocol(array $over = []): EnotfProtocol
{
    $p = (new EnotfProtocol)->forceFill($over + ['enr' => 'E'.uniqid()]);
    $p->save();

    return $p->fresh();
}

function en_role(string ...$perms): User
{
    $role = Role::create(['name' => 'R'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function en_crew(?int $vehicle, ?int $personnel = null): void
{
    app()->instance(CrewContext::class, new class($vehicle, $personnel) extends CrewContext
    {
        public function __construct(private ?int $v, private ?int $p) {}

        public function vehicleId(): ?int
        {
            return $this->v;
        }

        public function personnelId(): ?int
        {
            return $this->p;
        }
    });
}

it('enforces the unique ENR, the unique crew slot position, cascades and keeps category links', function () {
    $vehicle = Vehicle::factory()->create();
    $p = en_protocol(['enr' => '123', 'vehicle_transport_id' => $vehicle->id]);
    expect($p->status)->toBe(ProtocolStatus::Open)->and($p->revision)->toBe(1);
    expect(fn () => en_protocol(['enr' => '123']))->toThrow(QueryException::class);

    $seat = fn () => (new ProtocolCrew)->forceFill(['enotf_protocol_id' => $p->id, 'slot' => 'transport', 'position' => 1, 'name' => 'A'])->save();
    $seat();
    expect($seat)->toThrow(QueryException::class);

    $vehicle->delete();
    expect($p->fresh()->vehicle_transport_id)->toBeNull();
    $p->delete();
    expect(ProtocolCrew::count())->toBe(0);

    $cat = (new EnotfCategory)->forceFill(['name' => 'S', 'slug' => 's']);
    $cat->save();
    (new EnotfQuicklink)->forceFill(['enotf_category_id' => $cat->id, 'title' => 'T', 'url' => '/x'])->save();
    expect(fn () => $cat->delete())->toThrow(QueryException::class);
});

it('lets the assigned crew write while open, read when released, and nobody else in', function () {
    $this->seed(RoleSeeder::class);
    [$own, $other] = Vehicle::factory()->count(2)->create();
    $crew = User::factory()->create();
    $p = en_protocol(['vehicle_transport_id' => $own->id]);

    en_crew($other->id);
    expect($crew->can('view', $p))->toBeFalse()->and($crew->can('update', $p))->toBeFalse()->and($crew->can('viewAny', EnotfProtocol::class))->toBeTrue();

    en_crew($own->id);
    expect($crew->can('view', $p))->toBeTrue()->and($crew->can('update', $p))->toBeTrue()
        ->and($crew->can('withdraw', $p))->toBeTrue()->and($crew->can('viewQm', EnotfProtocol::class))->toBeFalse();
    $p->forceFill(['origin' => 'dispatch'])->save();
    expect($crew->can('withdraw', $p))->toBeFalse();

    $p->forceFill(['status' => 'released'])->save();
    expect($crew->can('view', $p))->toBeTrue()->and($crew->can('update', $p))->toBeFalse()->and($crew->can('release', $p))->toBeFalse();
    $p->forceFill(['status' => 'withdrawn'])->save();
    expect($crew->can('view', $p))->toBeFalse()->and($crew->can('print', $p))->toBeFalse();

    $q = en_protocol();
    $person = Personnel::factory()->create();
    (new ProtocolCrew)->forceFill(['enotf_protocol_id' => $q->id, 'slot' => 'na', 'position' => 1, 'personnel_id' => $person->id])->save();
    en_crew($other->id, $person->id);
    expect($crew->can('view', $q))->toBeTrue()->and($crew->can('update', $q))->toBeFalse();
});

it('lets QM and super-admins read, writes only with the real edivi.edit right, never as super-admin', function () {
    $this->seed(RoleSeeder::class);
    $vehicle = Vehicle::factory()->create();
    $p = en_protocol(['vehicle_transport_id' => $vehicle->id, 'status' => 'released']);
    $viewer = en_role('edivi.view');
    $editor = en_role('edivi.edit');
    $super = User::factory()->create(['is_super_admin' => true]);

    en_crew($vehicle->id);
    expect($viewer->can('view', $p))->toBeTrue()->and($viewer->can('viewQm', EnotfProtocol::class))->toBeTrue()
        ->and($viewer->can('update', $p))->toBeFalse()
        ->and($editor->can('update', $p))->toBeTrue()->and($editor->can('bulkDelete', EnotfProtocol::class))->toBeTrue()
        ->and($super->can('view', $p))->toBeTrue()->and($super->can('viewQm', EnotfProtocol::class))->toBeTrue()
        ->and($super->can('editProtocol', EnotfProtocol::class))->toBeFalse();

    $p->forceFill(['status' => 'open'])->save();
    expect($super->can('update', $p))->toBeFalse()->and($super->can('release', $p))->toBeFalse()
        ->and($super->can('withdraw', $p))->toBeFalse()->and($super->can('create', EnotfProtocol::class))->toBeFalse();
});

it('lets a crew member by personnel record read a released protocol without a crew session, read only', function () {
    $this->seed(RoleSeeder::class);
    $person = Personnel::factory()->create();
    $member = User::factory()->create(['personnel_id' => $person->id]);
    $stranger = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $p = en_protocol(['status' => 'released']);
    (new ProtocolCrew)->forceFill(['enotf_protocol_id' => $p->id, 'slot' => 'transport', 'position' => 1, 'personnel_id' => $person->id, 'name' => 'X'])->save();

    $this->actingAs($member)->get("/enotf/p/{$p->enr}")->assertOk()->assertInertia(fn ($page) => $page->where('readonly', true));
    $this->get("/enotf/print/{$p->enr}")->assertOk();
    $this->patchJson("/enotf/p/{$p->enr}", ['revision' => $p->revision, 'fields' => ['pat_vorname' => 'Anna']])->assertStatus(409);
    expect($p->fresh()->patient_first_name)->toBeNull();
    $this->actingAs($stranger)->get("/enotf/p/{$p->enr}")->assertForbidden();

    foreach ([['status' => 'open'], ['hidden_at' => now()], ['status' => 'withdrawn', 'hidden_at' => null]] as $state) {
        $p->forceFill($state)->save();
        $this->actingAs($member)->get("/enotf/p/{$p->enr}")->assertForbidden();
    }
});
