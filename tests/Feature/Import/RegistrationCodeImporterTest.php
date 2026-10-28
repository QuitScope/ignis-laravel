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

function seedThreeCodes(): void
{
    LegacySeed::codes([
        ['id' => 1, 'code' => 'FREE-1', 'label' => 'Für Max', 'created_by' => 1],
        ['id' => 2, 'code' => 'USED-2', 'created_by' => 1, 'used_by' => 2, 'used_at' => '2024-03-04 05:06:07', 'is_used' => 1],
        ['id' => 3, 'code' => 'OLD-3', 'created_by' => 2, 'expires_at' => '2020-01-01 00:00:00'],
    ]);
}

it('imports all codes with references, dates and ids mapped', function () {
    seedThreeCodes();

    $json = LegacySeed::run(['registration_code']);

    $u = DB::table('legacy_id_map')->where('entity', 'user')->pluck('new_id', 'legacy_id');
    $used = DB::table('registration_codes')->where('code', 'USED-2')->first();
    $old = DB::table('registration_codes')->where('code', 'OLD-3')->first();
    expect($json['entities']['registration_code'])->toBe(['read' => 3, 'written' => 3, 'skipped' => 0, 'failed' => 0])
        ->and(DB::table('registration_codes')->where('code', 'FREE-1')->value('label'))->toBe('Für Max')
        ->and((int) $used->created_by)->toBe((int) $u['1'])
        ->and((int) $used->used_by)->toBe((int) $u['2'])
        ->and($used->used_at)->toBe('2024-03-04 04:06:07')
        ->and($used->created_at)->toBe('2024-02-03 03:05:06')
        ->and($used->updated_at)->toBe('2024-02-03 03:05:06')
        ->and($old->expires_at)->toBe('2019-12-31 23:00:00')
        ->and((int) $old->created_by)->toBe((int) $u['2'])
        ->and($used->personnel_id)->toBeNull()
        ->and(DB::table('legacy_id_map')->where('entity', 'registration_code')->count())->toBe(3);
});

it('is idempotent and adopts an existing row with the same code', function () {
    seedThreeCodes();
    DB::table('registration_codes')->insert(['code' => 'FREE-1', 'label' => 'x', 'created_at' => now(), 'updated_at' => now()]);

    LegacySeed::run(['registration_code']);
    $json = LegacySeed::run(['registration_code']);

    expect(DB::table('registration_codes')->count())->toBe(3)
        ->and(DB::table('registration_codes')->where('code', 'FREE-1')->value('label'))->toBe('Für Max')
        ->and(DB::table('legacy_id_map')->where('entity', 'registration_code')->count())->toBe(3)
        ->and($json['entities']['registration_code']['failed'])->toBe(0);
});

it('skips empty and over-long codes', function () {
    LegacySeed::codes([
        ['id' => 1, 'code' => 'OK'],
        ['id' => 2, 'code' => str_repeat('x', 65)],
        ['id' => 3, 'code' => ' '],
        ['id' => 4, 'code' => str_repeat('y', 64)],
    ]);

    $json = LegacySeed::run(['registration_code']);

    expect($json['entities']['registration_code'])->toBe(['read' => 4, 'written' => 2, 'skipped' => 2, 'failed' => 0])
        ->and(DB::table('registration_codes')->orderBy('id')->pluck('code')->all())->toBe(['OK', str_repeat('y', 64)]);
});

it('sets used_at to created_at for used codes without it and warns once', function () {
    LegacySeed::codes([
        ['id' => 1, 'is_used' => 1, 'used_at' => null],
        ['id' => 2, 'is_used' => 1, 'used_at' => null],
    ]);

    $json = LegacySeed::run(['registration_code']);

    expect(DB::table('registration_codes')->pluck('used_at')->unique()->values()->all())->toBe(['2024-02-03 03:05:06'])
        ->and(collect($json['warnings'])->filter(fn ($w) => str_contains($w, 'used_at')))->toHaveCount(1);
});

it('nulls references to users that were not imported', function () {
    LegacySeed::codes([['id' => 1, 'created_by' => 999, 'used_by' => 998, 'is_used' => 1, 'used_at' => '2024-01-01 00:00:00']]);

    LegacySeed::run(['registration_code']);

    $row = DB::table('registration_codes')->first();
    expect($row->created_by)->toBeNull()->and($row->used_by)->toBeNull();
});

it('dry run leaves no trace', function () {
    seedThreeCodes();

    LegacySeed::run(['registration_code'], dry: true);

    expect(DB::table('registration_codes')->count())->toBe(0)
        ->and(User::count())->toBe(0)
        ->and(DB::table('legacy_id_map')->count())->toBe(0);
});

it('treats zero dates as empty with one aggregated warning', function () {
    LegacyDatabase::connection()->statement("SET SESSION sql_mode=''");
    LegacySeed::codes([
        ['id' => 1, 'expires_at' => '0000-00-00 00:00:00'],
        ['id' => 2, 'is_used' => 1, 'used_at' => '0000-00-00 00:00:00'],
    ]);

    $json = LegacySeed::run(['registration_code']);

    expect($json['entities']['registration_code'])->toBe(['read' => 2, 'written' => 2, 'skipped' => 0, 'failed' => 0])
        ->and(DB::table('registration_codes')->where('code', 'CODE1')->value('expires_at'))->toBeNull()
        ->and(DB::table('registration_codes')->where('code', 'CODE2')->value('used_at'))->toBe('2024-02-03 03:05:06')
        ->and(collect($json['warnings'])->filter(fn ($w) => str_contains($w, 'zero or invalid date')))->toHaveCount(1);
});
