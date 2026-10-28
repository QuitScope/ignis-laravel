<?php

use App\Dashboard\Widgets\HintsWidget;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Widgets\OwnIncidentsWidget;

uses(RefreshDatabase::class);

function sw_incident(array $over = []): FireIncident
{
    $i = (new FireIncident)->forceFill(['number' => 'E-'.uniqid(), 'location' => 'Hafenstrasse', 'started_at' => now(), ...$over]);
    $i->save();

    return $i;
}

it('searches incidents for QM only', function () {
    $this->seed(RoleSeeder::class);
    $i = sw_incident(['number' => 'E-77']);
    $role = Role::create(['name' => 'Q', 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['fire.incident.qm']);
    $qm = User::factory()->withRole($role)->create();
    $crew = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);

    $this->actingAs($qm)->getJson('/api/system/global-search?q=Hafenstr')->assertOk()
        ->assertJsonFragment(['href' => "/firetab/qm/incidents/{$i->id}"]);
    $this->actingAs($crew)->getJson('/api/system/global-search?q=Hafenstr')->assertOk()->assertJsonMissing(['href' => "/firetab/qm/incidents/{$i->id}"]);
});

it('shows only own incidents in the widget and the open ones as hint', function () {
    $me = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $mine = sw_incident(['leader_personnel_id' => $me->personnel_id]);
    sw_incident(['leader_personnel_id' => Personnel::factory()->create()->id]);
    $done = sw_incident(['leader_personnel_id' => $me->personnel_id, 'finalized_at' => now()->subDays(10)]);
    sw_incident(['leader_personnel_id' => $me->personnel_id, 'archived_at' => now()]);

    $data = (new OwnIncidentsWidget)->data($me);
    expect($data['total'])->toBe(2)->and(collect($data['items'])->pluck('open', 'id')->all())->toEqual([$mine->id => true, $done->id => false]);

    $hints = app(HintsWidget::class)->data($me)['hints'];
    expect(collect($hints)->pluck('title')->implode(' '))->toContain($mine->number);
});
