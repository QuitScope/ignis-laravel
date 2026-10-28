<?php

use App\Models\Role;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Federation\Models\FederationLink;
use Modules\Federation\Support\PeerSignature;

uses(RefreshDatabase::class);

const FEDM_ROUTES = [
    'settings' => ['GET', '/settings/federation'],
    'invite' => ['POST', '/settings/federation/invite'],
    'incidents' => ['GET', '/federation/incidents'],
    'protocols' => ['GET', '/federation/protocols'],
    'personnel' => ['GET', '/api/federation/personnel'],
    'pair' => ['POST', '/api/federation/pair'],
];

const FEDM_EXPECTED = [
    'guest' => ['settings' => 'login', 'invite' => 'login', 'incidents' => 'login', 'protocols' => 'login', 'personnel' => 401, 'pair' => 403],
    'user' => ['settings' => 403, 'invite' => 403, 'incidents' => 403, 'protocols' => 403, 'personnel' => 401, 'pair' => 403],
    'qm' => ['settings' => 403, 'invite' => 403, 'incidents' => 200, 'protocols' => 403, 'personnel' => 401, 'pair' => 403],
    'admin' => ['settings' => 200, 'invite' => 200, 'incidents' => 200, 'protocols' => 200, 'personnel' => 401, 'pair' => 403],
    'peer' => ['settings' => 'login', 'invite' => 'login', 'incidents' => 'login', 'protocols' => 'login', 'personnel' => 200, 'pair' => 403],
    'off' => ['settings' => 404, 'invite' => 404, 'incidents' => 404, 'protocols' => 404, 'personnel' => 404, 'pair' => 404],
];

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    app(Settings::class)->set('modules.federation.enabled', true);
});

function fedm_user(?string $permission): User
{
    $role = Role::create(['name' => 'X'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($permission ? [$permission] : []);

    return User::factory()->withRole($role)->create();
}

it('answers each key route per actor', function (string $actor) {
    $link = (new FederationLink)->forceFill(['instance_id' => (string) Str::uuid(), 'name' => 'P', 'url' => 'https://p.example',
        'secret_outgoing' => 'o', 'secret_incoming' => Str::random(40), 'provide_personnel' => true]);
    $link->save();
    match ($actor) {
        'user' => $this->actingAs(fedm_user(null)),
        'qm' => $this->actingAs(fedm_user('fire.incident.qm')),
        'admin', 'off' => $this->actingAs(fedm_user('admin')),
        default => null,
    };
    if ($actor === 'off') {
        app(Settings::class)->set('modules.federation.enabled', false);
    }

    foreach (FEDM_ROUTES as $key => [$method, $path]) {
        $headers = $actor === 'peer' && $method === 'GET'
            ? ['X-Ignis-Instance' => $link->instance_id] + PeerSignature::headers((new FederationLink)->forceFill(['secret_outgoing' => $link->secret_incoming]), 'GET', $path)
            : [];
        $res = $this->call($method, $path, [], [], [], $this->transformHeadersToServerVars($headers + ['Accept' => $key === 'invite' ? 'application/json' : 'text/html']));
        $want = FEDM_EXPECTED[$actor][$key];
        if ($want === 'login') {
            expect($res->status())->toBe($key === 'invite' ? 401 : 302, "$actor $key");
        } else {
            expect($res->status())->toBe($want, "$actor $key");
        }
    }
})->with(array_keys(FEDM_EXPECTED));

it('redirects the legacy settings url and shows the invite token only in the JSON answer', function () {
    $this->actingAs(fedm_user('admin'));
    $this->get('/settings/federation/index')->assertRedirect('/settings/federation')->assertStatus(301);

    $token = $this->postJson('/settings/federation/invite')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('token');
    $code = json_decode(base64_decode(strtr($token, '-_', '+/')), true)['code'];
    $audit = DB::table('activity_log')->where('log_name', 'Federation')->get()->toJson();
    expect($audit)->not->toContain($code)->not->toContain($token);
    $this->get('/settings/federation')->assertOk()->assertDontSee($code);
});
