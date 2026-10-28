<?php

use App\Models\Poi;
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

it('writes the legacy identifier and backfills it on a re-run for POIs imported without it', function () {
    LegacySeed::pois([['id' => 1, 'name' => 'KH Nord', 'typ' => 'Krankenhaus', 'legacy_identifier' => 'khn'], ['id' => 2, 'name' => 'Ohne', 'legacy_identifier' => ' ']]);
    LegacySeed::run(['poi']);
    $id = (int) Poi::query()->where('name', 'KH Nord')->value('id');
    DB::table('pois')->where('id', $id)->update(['legacy_identifier' => null]);

    LegacySeed::run(['poi']);

    expect(Poi::query()->whereKey($id)->value('legacy_identifier'))->toBe('khn')
        ->and(Poi::query()->count())->toBe(2)
        ->and(Poi::query()->where('name', 'Ohne')->value('legacy_identifier'))->toBeNull();
});
