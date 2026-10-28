<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Http::fake();
    Queue::fake();
    $role = Role::create(['name' => 'Adm'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['admin']);
    $this->admin = User::factory()->withRole($role)->create();
    $this->super = User::factory()->superAdmin()->create();
    $this->plain = User::factory()->create();
    $this->personnel = Personnel::factory()->create();
});

$routes = [
    'discord show' => ['GET', '/settings/system/discord'],
    'discord save' => ['PUT', '/settings/system/discord'],
    'discord disconnect' => ['DELETE', '/settings/system/discord'],
    'discord test' => ['POST', '/settings/system/discord/test'],
    'webhook save' => ['PUT', '/settings/system/discord/webhooks/enotf_protocol'],
    'webhook remove' => ['DELETE', '/settings/system/discord/webhooks/enotf_protocol'],
    'webhook test' => ['POST', '/settings/system/discord/webhooks/enotf_protocol/test'],
    'jobs page' => ['GET', '/settings/system/jobs'],
    'jobs run' => ['POST', '/settings/system/jobs/run'],
    'failed retry' => ['POST', '/settings/system/jobs/failed/abc/retry'],
    'failed delete' => ['DELETE', '/settings/system/jobs/failed/abc'],
    'failed delete all' => ['DELETE', '/settings/system/jobs/failed'],
    'invite via discord' => ['POST', '/personnel/PID/invite', ['via' => 'discord']],
];

it('sends a guest to the login, forbids a plain account and lets an admin and a super-admin in', function (string $method, string $url, array $data = []) {
    $url = str_replace('PID', (string) $this->personnel->id, $url);
    $this->call($method, $url, $data)->assertRedirect('/login');
    $this->actingAs($this->plain)->call($method, $url, $data)->assertForbidden();
    foreach ([$this->admin, $this->super] as $user) {
        $status = $this->actingAs($user)->call($method, $url, $data)->getStatusCode();
        expect($status)->not->toBeIn([401, 403, 404, 419, 429, 500]);
    }
})->with($routes);

it('lets every signed-in account switch its own Discord messages', function () {
    $this->post('/profile/discord-dm', ['discord_dm' => false])->assertRedirect('/login');
    foreach ([$this->plain, $this->admin, $this->super] as $user) {
        $this->actingAs($user)->post('/profile/discord-dm', ['discord_dm' => false])->assertRedirect();
        expect($user->fresh()->discord_dm)->toBeFalse();
    }
});
