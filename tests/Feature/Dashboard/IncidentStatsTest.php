<?php

use App\Dashboard\IncidentStats;
use App\Models\Role;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\FireTab\Models\FireIncident;

uses(RefreshDatabase::class);

it('counts incidents per hour and day in organisation time, only from allowed sources of active modules', function () {
    config(['ignis.timezone' => 'Europe/Berlin']);
    $this->seed(RoleSeeder::class);
    $role = Role::create(['name' => 'QM', 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['edivi.view', 'fire.incident.qm']);
    $qm = User::factory()->withRole($role)->create();
    $now = CarbonImmutable::parse('2026-10-20 12:10:00', 'UTC');
    $rd = fn (string $utc, array $over = []) => (new EnotfProtocol)->forceFill($over + ['enr' => (string) random_int(100000, 999999), 'created_at' => $utc])->save();
    $rd('2026-10-19 21:30:00');
    $rd('2026-10-19 22:30:00');
    $rd('2026-10-20 12:05:00');
    $rd('2026-10-20 12:06:00', ['hidden_at' => now()]);
    $rd('2026-10-20 12:07:00', ['status' => ProtocolStatus::Withdrawn]);
    $rd('2026-10-13 21:00:00');
    $rd('2026-10-19 11:00:00');
    FireIncident::query()->forceCreate(['number' => 'F-1', 'started_at' => '2026-10-20 06:00:00']);
    FireIncident::query()->forceCreate(['number' => 'F-2', 'started_at' => '2026-10-20 06:30:00', 'archived_at' => now()]);

    $stats = app(IncidentStats::class)->for($qm, $now);

    expect($stats)->toMatchArray(['today' => 3, 'rd' => 2, 'fire' => 1, 'yesterday' => 1])
        ->and($stats['hours'])->toHaveCount(15)
        ->and($stats['hours'][0])->toBe(1)->and($stats['hours'][8])->toBe(1)->and($stats['hours'][14])->toBe(1)
        ->and($stats['days'])->toBe([0, 0, 0, 0, 0, 2, 3]);

    expect(app(IncidentStats::class)->for(User::factory()->create(), $now))->toBeNull();
    app(Settings::class)->set('modules.firetab.enabled', false);
    expect(app(IncidentStats::class)->for($qm, $now))->toMatchArray(['today' => 2, 'rd' => 2, 'fire' => null]);
});
