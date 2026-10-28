<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyDatabase;
use Tests\Support\LegacySeed;

uses(RefreshDatabase::class)->group('legacy');

beforeEach(function () {
    if (! LegacySeed::boot()) {
        $this->markTestSkipped('LEGACY_TEST_DB_HOST not set');
    }
    $this->seed(RoleSeeder::class);
});

function seedFourUsers(): void
{
    LegacySeed::users([
        ['id' => 1, 'role' => 1, 'full_admin' => 1, 'username' => 'boss', 'fullname' => 'Big Boss', 'discord_id' => '111', 'aktenid' => 7, 'theme' => 'light'],
        ['id' => 2, 'role' => 2, 'is_active' => 0, 'deactivated_at' => '2025-05-06 07:08:09', 'deactivated_by' => 1, 'discord_id' => '222', 'theme' => 'system'],
        ['id' => 3, 'role' => 99, 'discord_id' => '555', 'username' => 'first'],
        ['id' => 4, 'role' => 2, 'discord_id' => '555', 'username' => 'second'],
    ]);
}

/** @param array<string, mixed> $json */
function reportWarnings(array $json): string
{
    return implode("\n", $json['warnings']);
}

it('imports users with roles, flags, deactivation and dedupes by discord id', function () {
    seedFourUsers();

    $json = LegacySeed::run(['user']);

    $boss = User::where('discord_id', '111')->first();
    $inactive = User::where('discord_id', '222')->first();
    $third = User::where('discord_id', '555')->first();
    expect($json['entities']['user'])->toBe(['read' => 4, 'written' => 3, 'skipped' => 1, 'failed' => 0])
        ->and(User::count())->toBe(3)
        ->and($boss->is_super_admin)->toBeTrue()
        ->and($boss->name)->toBe('Big Boss')
        ->and($boss->username)->toBe('boss')
        ->and($boss->role()->name)->toBe('Admin')
        ->and($boss->theme)->toBe('light')
        ->and($boss->personnel_id)->toBeNull()
        ->and($inactive->is_super_admin)->toBeFalse()
        ->and($inactive->is_active)->toBeFalse()
        ->and($inactive->deactivated_at->format('Y-m-d H:i:s'))->toBe('2025-05-06 05:08:09')
        ->and($inactive->deactivated_by)->toBe($boss->id)
        ->and($inactive->role()->name)->toBe('SGL')
        ->and($inactive->theme)->toBe('system')
        ->and($third->username)->toBe('first')
        ->and($third->role()->name)->toBe('Gast')
        ->and($json['skips'][0])->toContain('"legacy_id":"4"')->not->toContain('555')->toContain('"kept_legacy_id":"3"')
        ->and(reportWarnings($json))->toContain('default role')->toContain('legacy ids: 3')
        ->and($json['notes'])->not->toContain('1 Konten mit Aktenverknüpfung warten auf den Personal-Import')
        ->and(DB::table('legacy_id_map')->where('entity', 'user')->pluck('new_id', 'legacy_id')->map(fn ($v) => (int) $v)->all())
        ->toBe(['1' => $boss->id, '2' => $inactive->id, '3' => $third->id]);
});

it('preserves created_at, sets updated_at to it and is idempotent', function () {
    seedFourUsers();
    LegacySeed::run(['user']);
    $snapshot = fn () => User::orderBy('id')->get()->map(fn (User $u) => $u->only(['id', 'discord_id', 'created_at', 'updated_at', 'deactivated_by']))->all();
    $before = $snapshot();

    $json = LegacySeed::run(['user']);

    $user = User::where('discord_id', '111')->first();
    expect($user->created_at->format('Y-m-d H:i:s'))->toBe('2024-01-02 02:04:05')
        ->and($user->updated_at->format('Y-m-d H:i:s'))->toBe('2024-01-02 02:04:05')
        ->and($snapshot())->toEqual($before)
        ->and(DB::table('legacy_id_map')->where('entity', 'user')->count())->toBe(3)
        ->and($json['entities']['user']['failed'])->toBe(0)
        ->and(User::count())->toBe(3);
});

it('adopts an existing unmapped user with the same discord id and never demotes it', function () {
    $existing = User::factory()->superAdmin()->create(['discord_id' => '222', 'username' => 'installer', 'name' => 'Installer']);
    seedFourUsers();

    $json = LegacySeed::run(['user']);

    $existing->refresh();
    expect(User::count())->toBe(3)
        ->and($existing->is_super_admin)->toBeTrue()
        ->and($existing->username)->toBe('user2')
        ->and($existing->is_active)->toBeTrue()
        ->and($existing->deactivated_at)->toBeNull()
        ->and($existing->deactivated_by)->toBeNull()
        ->and($existing->role()->name)->toBe('SGL')
        ->and(reportWarnings($json))->toContain('operator account kept active (legacy account was inactive)')->toContain('legacy ids: 2')
        ->and((int) DB::table('legacy_id_map')->where('entity', 'user')->where('legacy_id', '2')->value('new_id'))->toBe($existing->id)
        ->and(implode(' ', $json['notes']))->toContain('Legacy user 2: adopted existing user')->not->toContain('222')
        ->and(LegacySeed::run(['user'])['entities']['user']['failed'])->toBe(0)
        ->and($existing->fresh()->is_super_admin)->toBeTrue()
        ->and($existing->fresh()->is_active)->toBeTrue()
        ->and($existing->fresh()->deactivated_by)->toBeNull();
});

it('deactivates an adopted non-admin user when legacy says inactive', function () {
    $existing = User::factory()->create(['discord_id' => '222']);
    seedFourUsers();

    LegacySeed::run(['user']);

    expect($existing->fresh()->is_active)->toBeFalse()
        ->and($existing->fresh()->deactivated_by)->not->toBeNull();
});

it('skips a mapped account whose discord id moved onto a non-imported target account', function () {
    seedFourUsers();
    LegacySeed::run(['user']);
    User::factory()->create(['discord_id' => '777']);
    LegacyDatabase::connection()->table('intra_users')->where('id', 3)->update(['discord_id' => '777']);

    $json = LegacySeed::run(['user']);

    expect(implode(' ', $json['skips']))->toContain('already used by a non-imported target account')
        ->and(User::where('discord_id', '555')->exists())->toBeTrue();
});

it('treats zero dates as empty with one aggregated warning', function () {
    LegacyDatabase::connection()->statement("SET SESSION sql_mode=''");
    LegacySeed::users([
        ['id' => 1, 'created_at' => '0000-00-00 00:00:00'],
        ['id' => 2, 'is_active' => 0, 'deactivated_at' => '0000-00-00 00:00:00'],
    ]);

    $json = LegacySeed::run(['user']);

    expect($json['entities']['user'])->toBe(['read' => 2, 'written' => 2, 'skipped' => 0, 'failed' => 0])
        ->and(User::where('discord_id', '100002')->value('deactivated_at'))->toBeNull()
        ->and(collect($json['warnings'])->filter(fn ($w) => str_contains($w, 'zero or invalid date')))->toHaveCount(1);
});

it('reports a failed row by class without leaking values', function () {
    seedFourUsers();
    User::saving(function (User $u) {
        if ($u->discord_id === '222') {
            throw new RuntimeException('row broke');
        }
    });

    $json = LegacySeed::run(['user']);

    expect($json['failures'][0])->toContain('legacy user 2')->toContain('RuntimeException');
});

it('skips accounts with an unusable discord id', function () {
    LegacySeed::users([
        ['id' => 1, 'discord_id' => ''],
        ['id' => 2, 'discord_id' => str_repeat('9', 21)],
        ['id' => 3, 'discord_id' => str_repeat('8', 20)],
    ]);

    $json = LegacySeed::run(['user']);

    expect($json['entities']['user'])->toBe(['read' => 3, 'written' => 1, 'skipped' => 2, 'failed' => 0])
        ->and(User::pluck('discord_id')->all())->toBe([str_repeat('8', 20)])
        ->and(implode(' ', $json['skips']))->toContain('discord_id')
        ->and($json['notes'])->toContain('1 Konten ohne Discord-ID nicht übernommen (z. B. Fabrica-Konten)');
});

it('repairs usernames, defaults is_active and falls back on the theme', function () {
    $db = LegacyDatabase::connection();
    $db->statement('ALTER TABLE intra_users MODIFY is_active TINYINT(1) NULL');
    try {
        LegacySeed::users([
            ['id' => 1, 'username' => ''],
            ['id' => 2, 'username' => str_repeat('é', 150)],
            ['id' => 3, 'username' => 'ok', 'is_active' => null],
        ]);
        $json = LegacySeed::run(['user']);
    } finally {
        $db->table('intra_users')->whereNull('is_active')->update(['is_active' => 1]);
        $db->statement('ALTER TABLE intra_users MODIFY is_active TINYINT(1) NOT NULL DEFAULT 1');
    }

    expect(User::where('discord_id', '100001')->value('username'))->toBe('user-1')
        ->and(mb_strlen(User::where('discord_id', '100002')->value('username')))->toBe(100)
        ->and(User::where('discord_id', '100003')->first()->is_active)->toBeTrue()
        ->and(User::where('discord_id', '100003')->value('theme'))->toBe('dark')
        ->and(reportWarnings($json))->toContain('empty username')->toContain('truncated');
});

it('creates users without a role when there is no default role', function () {
    DB::table('roles')->update(['is_default' => false]);
    LegacyDatabase::connection()->table('intra_users_roles')->update(['default' => 0]);
    LegacySeed::users([['id' => 1, 'role' => 99]]);

    $json = LegacySeed::run(['user']);

    expect(User::first()->roles)->toHaveCount(0)
        ->and(reportWarnings($json))->toContain('no default role');
});

it('sets deactivated_by to null when the referenced account was not imported', function () {
    seedFourUsers();
    LegacyDatabase::connection()->table('intra_users')->where('id', 2)->update(['deactivated_by' => 4]);
    LegacyDatabase::connection()->table('intra_users')->where('id', 3)->update(['deactivated_by' => 77]);

    LegacySeed::run(['user']);

    expect(User::where('discord_id', '222')->value('deactivated_by'))->toBeNull()
        ->and(User::where('discord_id', '555')->value('deactivated_by'))->toBeNull();
});

it('counts a failing row as failed and continues', function () {
    seedFourUsers();
    User::saving(function (User $u) {
        if ($u->discord_id === '222') {
            throw new RuntimeException('row broke');
        }
    });

    $json = LegacySeed::run(['user']);

    expect($json['entities']['user'])->toBe(['read' => 4, 'written' => 2, 'skipped' => 1, 'failed' => 1])
        ->and(User::where('discord_id', '222')->exists())->toBeFalse()
        ->and(DB::table('legacy_id_map')->where('entity', 'user')->where('legacy_id', '2')->exists())->toBeFalse()
        ->and($json['exit'])->toBe(1);
});

it('dry run leaves no trace', function () {
    seedFourUsers();
    $before = User::count();

    $json = LegacySeed::run(['user'], dry: true);

    expect(User::count())->toBe($before)
        ->and(DB::table('legacy_id_map')->count())->toBe(0)
        ->and(DB::table('model_has_roles')->count())->toBe(0)
        ->and($json['entities']['user']['written'])->toBe(3);
});

it('does not touch the legacy database', function () {
    seedFourUsers();

    LegacySeed::run(['user']);

    expect(LegacyDatabase::connection()->table('intra_users')->count())->toBe(4);
});
