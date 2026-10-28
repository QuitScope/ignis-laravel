<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LegacyDatabase;
use Tests\Support\LegacySeed;

uses(RefreshDatabase::class)->group('legacy');

beforeEach(function () {
    if (! LegacySeed::boot()) {
        $this->markTestSkipped('LEGACY_TEST_DB_HOST not set');
    }
    $this->seed(RoleSeeder::class);
});

function withNullableLegacyColumns(Closure $fn): void
{
    $db = LegacyDatabase::connection();
    $db->statement('ALTER TABLE intra_users MODIFY theme VARCHAR(10) NULL, MODIFY discord_dm TINYINT(1) NULL');
    try {
        $fn();
    } finally {
        $db->table('intra_users')->whereNull('theme')->update(['theme' => 'dark']);
        $db->table('intra_users')->whereNull('discord_dm')->update(['discord_dm' => 1]);
        $db->statement("ALTER TABLE intra_users MODIFY theme VARCHAR(10) NOT NULL DEFAULT 'dark', MODIFY discord_dm TINYINT(1) NOT NULL DEFAULT 1");
    }
}

it('takes dark, light and system as they are and sends anything else to dark with a counted warning', function () {
    withNullableLegacyColumns(function () use (&$json) {
        LegacySeed::users([
            ['id' => 1, 'theme' => 'dark'],
            ['id' => 2, 'theme' => 'light'],
            ['id' => 3, 'theme' => 'system'],
            ['id' => 4, 'theme' => 'neon'],
            ['id' => 5, 'theme' => 'Light'],
            ['id' => 6, 'theme' => null],
        ]);
        $json = LegacySeed::run(['user']);
    });

    $theme = fn (int $id) => User::where('discord_id', (string) (100000 + $id))->value('theme');
    $warnings = implode("\n", $json['warnings']);

    expect(array_map($theme, [1, 2, 3, 4, 5, 6]))->toBe(['dark', 'light', 'system', 'dark', 'dark', 'dark'])
        ->and($warnings)->toContain('2 user(s): theme is not dark/light/system, set to dark (legacy ids: 4, 5)')
        ->and($warnings)->not->toContain('system, set to dark (legacy ids: 3');
});

it('imports discord_dm as a boolean, NULL as on', function () {
    withNullableLegacyColumns(function () {
        LegacySeed::users([
            ['id' => 1, 'discord_dm' => 0],
            ['id' => 2, 'discord_dm' => 1],
            ['id' => 3, 'discord_dm' => null],
            ['id' => 4, 'discord_dm' => 2],
        ]);
        LegacySeed::run(['user']);
    });

    expect(User::orderBy('discord_id')->pluck('discord_dm', 'discord_id')->all())
        ->toBe(['100001' => false, '100002' => true, '100003' => true, '100004' => true]);
});

it('defaults discord_dm to on when the legacy column does not exist yet', function () {
    $db = LegacyDatabase::connection();
    $db->statement('ALTER TABLE intra_users DROP COLUMN discord_dm');
    try {
        LegacySeed::users([['id' => 1, 'theme' => 'system']]);
        $json = LegacySeed::run(['user']);
    } finally {
        $db->statement('ALTER TABLE intra_users ADD COLUMN discord_dm TINYINT(1) NOT NULL DEFAULT 1 AFTER theme');
    }

    $user = User::where('discord_id', '100001')->first();
    expect($json['exit'])->toBe(0)->and($user->discord_dm)->toBeTrue()->and($user->theme)->toBe('system');
});

it('lets a re-run bring the legacy values back', function () {
    LegacySeed::users([['id' => 1, 'theme' => 'light', 'discord_dm' => 0]]);
    LegacySeed::run(['user']);
    User::query()->update(['theme' => 'dark', 'discord_dm' => true]);

    LegacySeed::run(['user']);

    expect(User::first()->only('theme', 'discord_dm'))->toBe(['theme' => 'light', 'discord_dm' => false]);
});
