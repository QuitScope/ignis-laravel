<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentVehicle;
use Modules\ManvBoard\Models\ManvIncident;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $channels = app(BroadcastFactory::class)->driver('null')->getChannels();
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => [
        'driver' => 'reverb', 'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => '1', 'options' => ['host' => '127.0.0.1', 'port' => 8081, 'scheme' => 'http'],
    ]]);
    $reverb = app(BroadcastFactory::class)->driver('reverb');
    foreach ($channels as $name => $callback) {
        $reverb->channel($name, $callback);
    }
});

function p11_member(): User
{
    return User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
}

function p11_firetab(User $user, Vehicle $vehicle): void
{
    test()->actingAs($user)->withSession(['crew.firetab' => ['user_id' => $user->id, 'vehicle_id' => $vehicle->id, 'personnel_id' => $user->personnel_id, 'since' => time()]]);
}

function p11_mci(): User
{
    $role = Role::create(['name' => 'M'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['mci.view']);

    return User::factory()->withRole($role)->create();
}

/**
 * Sets the caller up for $who and returns the channel name (without `private-`).
 *
 * @return array<string, Closure(string): string>
 */
function p11_channels(): array
{
    $fire = fn () => Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);

    return [
        'user' => function (string $who) {
            $own = p11_member();
            match ($who) {
                'foreign' => test()->actingAs(p11_member()),
                'super' => test()->actingAs(User::factory()->create(['is_super_admin' => true])),
                'guest' => null,
                default => test()->actingAs($own),
            };

            return "user.{$own->id}";
        },
        'firetab.incident' => function (string $who) use ($fire) {
            $vehicle = $fire();
            $incident = (new FireIncident)->forceFill(['location' => 'Hauptstr. 1', 'keyword' => 'B3', 'started_at' => now()]);
            $incident->save();
            (new FireIncidentVehicle)->forceFill(['fire_incident_id' => $incident->id, 'vehicle_id' => $vehicle->id, 'name' => $vehicle->name])->save();
            match ($who) {
                'foreign' => p11_firetab(p11_member(), $fire()),
                'super' => test()->actingAs(User::factory()->create(['is_super_admin' => true])),
                'guest' => null,
                default => p11_firetab(p11_member(), $vehicle),
            };

            return "firetab.incident.{$incident->id}";
        },
        'firetab.vehicle' => function (string $who) use ($fire) {
            $vehicle = $fire();
            match ($who) {
                'foreign' => p11_firetab(p11_member(), $fire()),
                'super' => test()->actingAs(User::factory()->create(['is_super_admin' => true])),
                'guest' => null,
                default => p11_firetab(p11_member(), $vehicle),
            };

            return "firetab.vehicle.{$vehicle->id}";
        },
        'enotf.vehicle' => function (string $who) {
            $ems = fn () => Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS]);
            $vehicle = $ems();
            $login = fn (Vehicle $v) => test()->actingAs(p11_member())->post('/enotf/login', ['vehicle_id' => $v->id, 'pin' => '1234'])->assertRedirect('/enotf/overview');
            match ($who) {
                'foreign' => $login($ems()),
                'super' => test()->actingAs(User::factory()->create(['is_super_admin' => true])),
                'guest' => null,
                default => $login($vehicle),
            };

            return "enotf.vehicle.{$vehicle->id}";
        },
        'manv' => function (string $who) {
            match ($who) {
                'foreign' => test()->actingAs(p11_member()),
                'super' => test()->actingAs(User::factory()->create(['is_super_admin' => true])),
                'guest' => null,
                default => test()->actingAs(p11_mci()),
            };

            return 'manv';
        },
        'manv.incident' => function (string $who) {
            $incident = (new ManvIncident)->forceFill(['incident_number' => 'L-1', 'location' => 'Halle', 'started_at' => now()]);
            $incident->save();
            match ($who) {
                'foreign' => test()->actingAs(p11_member()),
                'super' => test()->actingAs(User::factory()->create(['is_super_admin' => true])),
                'guest' => null,
                default => test()->actingAs(p11_mci()),
            };

            return "manv.{$incident->id}";
        },
    ];
}

dataset('p11_channels', [
    'user.{id}' => ['user', null, [401, 403, 200, 403, 200]],
    'firetab.incident.{incident}' => ['firetab.incident', 'firetab', [401, 403, 200, 200, 403]],
    'firetab.vehicle.{vehicle}' => ['firetab.vehicle', 'firetab', [401, 403, 200, 403, 403]],
    'enotf.vehicle.{vehicle}' => ['enotf.vehicle', 'enotf', [401, 403, 200, 403, 403]],
    'manv' => ['manv', 'manvboard', [401, 403, 200, 200, 403]],
    'manv.{incident}' => ['manv.incident', 'manvboard', [401, 403, 200, 200, 403]],
]);

it('authorises each channel only for its entitled callers', function (string $channel, ?string $module, array $codes) {
    $got = [];
    foreach (['guest', 'foreign', 'entitled', 'super', 'off'] as $who) {
        auth()->forgetGuards();
        $this->flushSession();
        if ($module !== null) {
            app(Settings::class)->set("modules.$module.enabled", true);
        }
        $name = p11_channels()[$channel]($who === 'off' ? 'entitled' : $who);
        if ($module !== null && $who === 'off') {
            app(Settings::class)->set("modules.$module.enabled", false);
        }
        if ($who === 'guest') {
            auth()->logout();
        }
        $got[$who] = $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-$name"])->status();
    }

    expect(array_values($got))->toBe($codes);
})->with('p11_channels');

it('refuses a non-numeric channel id with 403, not 500', function () {
    $this->actingAs(p11_member());
    foreach (['user', 'firetab.vehicle', 'enotf.vehicle'] as $prefix) {
        $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-$prefix.abc"])->assertForbidden();
    }
});

it('keeps the CEF session cookie SameSite=None on /broadcasting/auth', function () {
    $user = p11_member();
    $response = $this->actingAs($user)->withServerVariables(['HTTPS' => 'on'])
        ->withHeader('User-Agent', 'Mozilla/5.0 CitizenFX/1.0')
        ->postJson('https://localhost/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-user.{$user->id}"])->assertOk();

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
    expect($cookie?->getSameSite())->toBe('none')->and($cookie?->isSecure())->toBeTrue();
});
