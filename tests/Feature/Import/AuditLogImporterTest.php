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
    LegacySeed::users([['id' => 1], ['id' => 2]]);
});

/**
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function legacyAuditRow(int $id, array $over = []): array
{
    return $over + [
        'id' => $id, 'user' => 1, 'module' => 'Users', 'action' => "Aktion {$id}", 'details' => "Details {$id}",
        'context' => null, 'global' => 0, 'timestamp' => '2024-05-06 07:08:09',
    ];
}

it('maps one row field by field', function () {
    LegacySeed::audit([legacyAuditRow(5, ['user' => 2, 'global' => 1, 'context' => '{"role_id": 3}', 'timestamp' => '2023-01-02 03:04:05'])]);

    $json = LegacySeed::run(['audit']);

    $row = DB::table('activity_log')->where('legacy_id', 5)->first();
    $userId = DB::table('legacy_id_map')->where('entity', 'user')->where('legacy_id', '2')->value('new_id');
    expect($json['entities']['audit'])->toBe(['read' => 1, 'written' => 1, 'skipped' => 0, 'failed' => 0])
        ->and($row->log_name)->toBe('Users')
        ->and($row->description)->toBe('Aktion 5')
        ->and($row->details)->toBe('Details 5')
        ->and((bool) $row->is_global)->toBeTrue()
        ->and($row->causer_type)->toBe(User::class)
        ->and((int) $row->causer_id)->toBe((int) $userId)
        ->and($row->event)->toBeNull()
        ->and(json_decode($row->properties, true))->toBe(['context' => ['role_id' => 3]])
        ->and($row->created_at)->toBe('2023-01-02 03:04:05')
        ->and($row->updated_at)->toBe('2023-01-02 03:04:05');
});

it('imports 2500 rows in three chunks, keeps the count and timestamps, and is idempotent', function () {
    $rows = [];
    for ($i = 1; $i <= 2500; $i++) {
        $rows[] = legacyAuditRow($i, ['timestamp' => sprintf('2024-01-%02d 10:00:00', ($i % 28) + 1)]);
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        LegacySeed::audit($chunk);
    }

    $json = LegacySeed::run(['audit']);
    $again = LegacySeed::run(['audit']);

    expect($json['entities']['audit'])->toBe(['read' => 2500, 'written' => 2500, 'skipped' => 0, 'failed' => 0])
        ->and($json['notes'])->toContain('audit: 3 chunks of up to 1000 rows')
        ->and(DB::table('activity_log')->whereNotNull('legacy_id')->count())->toBe(2500)
        ->and(DB::table('activity_log')->where('legacy_id', 1500)->value('created_at'))->toBe('2024-01-17 10:00:00')
        ->and($again['entities']['audit']['failed'])->toBe(0)
        ->and(DB::table('activity_log')->whereNotNull('legacy_id')->count())->toBe(2500);
});

it('updates a row on re-run instead of duplicating it', function () {
    LegacySeed::audit([legacyAuditRow(1)]);
    LegacySeed::run(['audit']);
    LegacyDatabase::connection()->table('intra_audit_log')->where('id', 1)->update(['action' => 'Geändert']);

    LegacySeed::run(['audit']);

    expect(DB::table('activity_log')->where('legacy_id', 1)->count())->toBe(1)
        ->and(DB::table('activity_log')->where('legacy_id', 1)->value('description'))->toBe('Geändert');
});

it('keeps legacy_user_id for unmapped users and leaves the causer empty', function () {
    LegacySeed::audit([legacyAuditRow(1, ['user' => 0]), legacyAuditRow(2, ['user' => 4242, 'context' => '{"a":1}'])]);

    $json = LegacySeed::run(['audit']);

    $rows = DB::table('activity_log')->whereNotNull('legacy_id')->orderBy('legacy_id')->get();
    expect($rows[0]->causer_id)->toBeNull()->and($rows[0]->causer_type)->toBeNull()
        ->and(json_decode($rows[0]->properties, true))->toBe(['legacy_user_id' => 0])
        ->and(json_decode($rows[1]->properties, true))->toBe(['context' => ['a' => 1], 'legacy_user_id' => 4242])
        ->and(implode(' ', $json['warnings']))->toContain('unmapped');
});

it('stores rows with invalid context json without context and warns once', function () {
    LegacyDatabase::connection()->statement('ALTER TABLE intra_audit_log MODIFY context LONGTEXT NULL');
    LegacySeed::audit([legacyAuditRow(1, ['context' => '{oops']), legacyAuditRow(2, ['context' => 'nope'])]);

    $json = LegacySeed::run(['audit']);

    expect(DB::table('activity_log')->whereNotNull('legacy_id')->count())->toBe(2)
        ->and(json_decode((string) DB::table('activity_log')->where('legacy_id', 1)->value('properties'), true))->not->toHaveKey('context')
        ->and(collect($json['warnings'])->filter(fn ($w) => str_contains($w, 'context')))->toHaveCount(1);
});

it('uses System for a missing module and keeps native entries untouched', function () {
    LegacySeed::audit([legacyAuditRow(1, ['module' => null])]);
    DB::table('activity_log')->insert(['description' => 'native', 'created_at' => '2030-01-01 00:00:00', 'updated_at' => '2030-01-01 00:00:00']);

    LegacySeed::run(['audit']);

    expect(DB::table('activity_log')->where('legacy_id', 1)->value('log_name'))->toBe('System')
        ->and(DB::table('activity_log')->where('description', 'native')->value('created_at'))->toBe('2030-01-01 00:00:00');
});

it('dry run leaves no trace', function () {
    LegacySeed::audit([legacyAuditRow(1)]);
    $before = DB::table('activity_log')->count();

    $json = LegacySeed::run(['audit'], dry: true);

    expect(DB::table('activity_log')->count())->toBe($before)
        ->and(DB::table('legacy_id_map')->count())->toBe(0)
        ->and($json['entities']['audit']['written'])->toBe(1);
});

it('keeps TIMESTAMP instants unchanged around DST switches (legacy server in UTC)', function () {
    LegacySeed::audit([
        legacyAuditRow(1, ['timestamp' => '2026-01-15 12:00:00']),
        legacyAuditRow(2, ['timestamp' => '2026-07-15 12:00:00']),
        legacyAuditRow(3, ['timestamp' => '2026-03-29 02:30:00']),
        legacyAuditRow(4, ['timestamp' => '2026-10-25 02:30:00']),
    ]);

    $json = LegacySeed::run(['audit']);

    expect(DB::table('activity_log')->where('legacy_id', 1)->value('created_at'))->toBe('2026-01-15 12:00:00')
        ->and(DB::table('activity_log')->where('legacy_id', 2)->value('created_at'))->toBe('2026-07-15 12:00:00')
        ->and(DB::table('activity_log')->where('legacy_id', 2)->value('updated_at'))->toBe('2026-07-15 12:00:00')
        ->and($json['entities']['audit']['failed'])->toBe(0)
        ->and(DB::table('activity_log')->whereNotNull('legacy_id')->count())->toBe(4);
});

it('treats a zero timestamp as missing, uses the import time and warns once', function () {
    LegacyDatabase::connection()->statement("SET SESSION sql_mode=''");
    LegacySeed::audit([legacyAuditRow(1, ['timestamp' => '0000-00-00 00:00:00']), legacyAuditRow(2, ['timestamp' => '0000-00-00 00:00:00'])]);

    $json = LegacySeed::run(['audit']);

    expect(DB::table('activity_log')->where('legacy_id', 1)->value('created_at'))->not->toStartWith('0000')
        ->and($json['entities']['audit']['failed'])->toBe(0)
        ->and(collect($json['warnings'])->filter(fn ($w) => str_contains($w, 'zero or invalid')))->toHaveCount(1);
});

it('reads the TIMESTAMP column as an instant, independent of the legacy server zone and DST', function () {
    config(['ignis.import.legacy_timezone' => 'UTC']);
    LegacyDatabase::connection()->statement("SET time_zone = '+00:00'");
    LegacySeed::audit([
        legacyAuditRow(1, ['timestamp' => '2024-01-15 12:00:00']),
        legacyAuditRow(2, ['timestamp' => '2024-07-15 12:00:00']),
        legacyAuditRow(3, ['timestamp' => '2024-03-31 00:30:00']),
    ]);
    LegacyDatabase::connection()->statement("SET GLOBAL time_zone = 'Europe/Berlin'");

    try {
        $json = LegacySeed::run(['audit']);
    } finally {
        LegacyDatabase::connection()->statement("SET GLOBAL time_zone = 'SYSTEM'");
    }

    $at = fn (int $id) => DB::table('activity_log')->where('legacy_id', $id)->value('created_at');
    expect($json['entities']['audit']['failed'])->toBe(0)
        ->and($at(1))->toBe('2024-01-15 12:00:00')
        ->and($at(2))->toBe('2024-07-15 12:00:00')
        ->and($at(3))->toBe('2024-03-31 00:30:00');
});
