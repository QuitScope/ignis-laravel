<?php

use App\Models\Role;
use App\Models\User;
use App\Update\Maintenance;
use App\Update\ProcessUpdateStarter;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\MaintenanceSandbox;
use Tests\Support\UpdateFixtures;

uses(RefreshDatabase::class);

/** Never starts a process: the matrix only asks who may reach a route. */
class RmNoLaunch extends ProcessUpdateStarter
{
    public function start(string $version, bool $skipTrial = false): void {}

    public function rollback(): void {}
}

beforeEach(function () {
    MaintenanceSandbox::enter();
    Http::preventStrayRequests();
    $this->seed(RoleSeeder::class);
    UpdateFixtures::setUp('1.0.0');
    config(['ignis.update.driver' => 'files', 'ignis.update.state_path' => sys_get_temp_dir().'/ignis-rm-'.bin2hex(random_bytes(4)).'/state.json']);
    app()->instance(ProcessUpdateStarter::class, new RmNoLaunch);
    $role = Role::create(['name' => 'Adm'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['admin']);
    $this->admin = User::factory()->withRole($role)->create();
    $this->super = User::factory()->superAdmin()->create();
    $this->plain = User::factory()->create();
});

$routes = [
    'check' => ['POST', '/settings/system/update/check'],
    'start' => ['POST', '/settings/system/update', ['version' => '2.0.0']],
    'status' => ['GET', '/settings/system/update/status'],
    'rollback' => ['POST', '/settings/system/update/rollback', ['confirm' => true]],
    'backups' => ['GET', '/settings/system/update/backups'],
];

it('sends a guest to the login, forbids a plain account and lets an admin and a super-admin in', function (string $method, string $url, array $data = []) {
    $this->call($method, $url, $data)->assertRedirect('/login');
    $this->actingAs($this->plain)->call($method, $url, $data)->assertForbidden();
    foreach ([$this->admin, $this->super] as $user) {
        $status = $this->actingAs($user)->call($method, $url, $data, [], [], ['HTTP_ACCEPT' => 'application/json'])->getStatusCode();
        expect($status)->not->toBeIn([401, 403, 404, 419, 500]);
    }
})->with($routes);

it('answers the status to an admin in maintenance mode and to nobody else', function () {
    (new Maintenance)->enable();
    $json = ['HTTP_ACCEPT' => 'application/json'];

    $this->call('GET', '/settings/system/update/status', [], [], [], $json)->assertStatus(401);
    $this->actingAs($this->plain)->call('GET', '/settings/system/update/status', [], [], [], $json)->assertForbidden();
    foreach ([$this->admin, $this->super] as $user) {
        $this->actingAs($user)->call('GET', '/settings/system/update/status', [], [], [], $json)->assertOk()->assertJsonPath('maintenance', true);
    }
    $this->actingAs($this->admin)->call('GET', '/settings/system/update/backups', [], [], [], $json)->assertStatus(503);
});
