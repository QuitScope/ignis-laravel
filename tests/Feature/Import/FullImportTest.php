<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacySeed;

uses(RefreshDatabase::class)->group('legacy');

beforeEach(function () {
    if (! LegacySeed::boot()) {
        $this->markTestSkipped('LEGACY_TEST_DB_HOST not set');
    }
    $this->seed(RoleSeeder::class);
});

/** @return array<string, int> */
function targetCounts(): array
{
    return collect(['users', 'registration_codes', 'activity_log', 'settings', 'legacy_id_map', 'roles', 'model_has_roles'])
        ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
}

function seedEverything(): void
{
    LegacySeed::users([['id' => 1, 'full_admin' => 1, 'role' => 1], ['id' => 2, 'deactivated_by' => 1, 'is_active' => 0]]);
    LegacySeed::codes([['id' => 1, 'created_by' => 1], ['id' => 2, 'used_by' => 2, 'is_used' => 1]]);
    LegacySeed::audit([
        ['id' => 1, 'user' => 1, 'module' => 'A', 'action' => 'x', 'details' => null, 'context' => null, 'global' => 1, 'timestamp' => '2024-01-01 00:00:00'],
        ['id' => 2, 'user' => 9, 'module' => 'B', 'action' => 'y', 'details' => null, 'context' => null, 'global' => 0, 'timestamp' => '2024-01-02 00:00:00'],
    ]);
    LegacySeed::config(['API_KEY' => str_repeat('k', 32), 'REGISTRATION_MODE' => 'code', 'TABLET_LOGIN_ENABLED' => 'true']);
}

it('imports all entities twice with identical row counts', function () {
    seedEverything();

    $first = LegacySeed::run();
    $afterFirst = targetCounts();
    $second = LegacySeed::run();

    expect($first['exit'])->toBe(0)->and($second['exit'])->toBe(0)
        ->and($afterFirst['users'])->toBe(2)
        ->and($afterFirst['registration_codes'])->toBe(2)
        ->and(DB::table('activity_log')->whereNotNull('legacy_id')->count())->toBe(2);
    $afterSecond = targetCounts();
    expect($afterSecond['activity_log'])->toBe($afterFirst['activity_log'] + 1);
    unset($afterFirst['activity_log'], $afterSecond['activity_log']);
    expect($afterSecond)->toBe($afterFirst);
});

it('dry run leaves users, codes, audit, settings and the id map unchanged', function () {
    seedEverything();
    $before = targetCounts();

    $json = LegacySeed::run(dry: true);

    expect($json['exit'])->toBe(0)->and(targetCounts())->toBe($before);
});
