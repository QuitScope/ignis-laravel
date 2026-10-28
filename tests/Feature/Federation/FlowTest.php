<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Federation\Models\FederatedIncident;
use Modules\Federation\Models\FederationLink;
use Modules\Federation\Support\PeerHttp;
use Modules\FireTab\Models\FireIncident;

uses(RefreshDatabase::class);

afterEach(fn () => PeerHttp::resolveUsing(null));

it('pairs, syncs, picks a federation leader and applies a deletion marker', function () {
    $this->seed(RoleSeeder::class);
    app(Settings::class)->set('modules.federation.enabled', true);
    PeerHttp::resolveUsing(fn () => ['93.184.216.34']);
    $role = Role::create(['name' => 'Admin'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['admin']);
    $admin = User::factory()->withRole($role)->create(['personnel_id' => Personnel::factory()->create()->id]);

    $a = (string) Str::uuid();
    $secret = str_repeat('k', 64);
    $tombstone = false;
    Http::fake(function (Request $r) use ($a, $secret, &$tombstone) {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        $incident = ['id' => 7, 'visible' => true, 'incident_number' => 'A-7', 'keyword' => 'B3', 'location' => 'Markt', 'status' => 'active', 'leader_name' => 'Chief', 'updated_at' => '2026-10-20T10:00:00+00:00'];

        return match ($path) {
            '/api/federation/pair' => Http::response(['success' => true, 'instance_id' => $a, 'name' => 'Wache A', 'secret_for_you' => $secret]),
            '/api/federation/handshake' => Http::response(['success' => true, 'version' => 2]),
            '/api/federation/personnel' => Http::response(['data' => [['id' => 3, 'full_name' => 'Erika Muster']], 'cursor' => 'p', 'next' => null]),
            '/api/federation/fire-incidents' => Http::response(['data' => [$tombstone ? ['id' => 7, 'visible' => false] : $incident], 'cursor' => $tombstone ? 'c2' : 'c1', 'next' => null]),
        };
    });
    $token = rtrim(strtr(base64_encode(json_encode(['v' => 2, 'url' => 'https://a.example', 'instance_id' => $a, 'name' => 'A', 'code' => 'c0de'])), '+/', '-_'), '=');

    $this->actingAs($admin)->post('/settings/federation/pair', ['token' => $token])->assertRedirect('/settings/federation')->assertSessionHas('status', 'federation.settings.paired');
    $link = FederationLink::query()->where('instance_id', $a)->firstOrFail();
    expect($link->confirmed_at)->not->toBeNull()
        ->and(DB::table('federation_links')->value('secret_outgoing'))->not->toContain($secret);

    $flags = ['consume_personnel' => true, 'consume_enotf' => false, 'consume_fire' => true, 'provide_personnel' => false, 'provide_enotf' => false, 'provide_fire' => false];
    $this->put("/settings/federation/links/{$link->id}", $flags + ['is_active' => true, 'sync_interval_minutes' => 30])->assertSessionHasNoErrors();
    $this->post("/settings/federation/links/{$link->id}/sync")->assertSessionHas('status', 'federation.settings.sync_queued');
    expect(FederatedIncident::query()->value('number'))->toBe('A-7');
    $this->get('/federation/incidents')->assertOk()->assertSee('Wache A');

    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    $this->withSession(['crew.firetab' => ['user_id' => $admin->id, 'vehicle_id' => $vehicle->id, 'personnel_id' => $admin->personnel_id, 'since' => time()]]);
    $hit = collect($this->getJson('/firetab/personnel/search?q=Erika')->assertOk()->json())->firstWhere('name', 'Erika Muster [Wache A]');
    expect($hit['id'])->toBe("fed:{$link->id}:3");
    $this->post('/firetab/incidents', ['location' => 'Hof', 'keyword' => 'B1', 'started_date' => '2026-10-20', 'started_time' => '10:00', 'leader_personnel_id' => $hit['id']])->assertRedirect();
    $incident = FireIncident::query()->latest('id')->firstOrFail();
    expect($incident->leader_personnel_id)->toBeNull()->and($incident->leader_external_name)->toBe('Erika Muster [Wache A]');
    $this->put("/firetab/incidents/{$incident->id}", ['location' => 'Hof 2', 'keyword' => 'B1', 'started_date' => '2026-10-20', 'started_time' => '10:00', 'leader_personnel_id' => 'external'])->assertSessionHasNoErrors();
    expect($incident->fresh()->leader_external_name)->toBe('Erika Muster [Wache A]');
    $this->post('/firetab/incidents', ['location' => 'Hof', 'keyword' => 'B1', 'started_date' => '2026-10-20', 'started_time' => '10:00', 'leader_personnel_id' => "fed:{$link->id}:99"])
        ->assertSessionHasErrors('leader_personnel_id');

    $tombstone = true;
    $link->forceFill(['last_sync_at' => null])->save();
    $this->post("/settings/federation/links/{$link->id}/sync");
    expect(FederatedIncident::query()->count())->toBe(0);
    $audit = DB::table('activity_log')->where('log_name', 'Federation')->get()->toJson();
    expect($audit)->not->toContain($secret)->not->toContain('c0de')->not->toContain($link->fresh()->secret_incoming);

    $this->delete("/settings/federation/links/{$link->id}")->assertRedirect('/settings/federation');
    expect(FederationLink::query()->count())->toBe(0)->and(DB::table('federation_personnel')->count())->toBe(0);
});
