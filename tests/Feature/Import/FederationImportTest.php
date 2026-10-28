<?php

use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Federation\Models\FederationLink;
use Tests\Support\LegacyDatabase;
use Tests\Support\LegacySeed;

uses(RefreshDatabase::class)->group('legacy');

beforeEach(function () {
    if (! LegacySeed::boot()) {
        $this->markTestSkipped('LEGACY_TEST_DB_HOST not set');
    }
    $this->seed(RoleSeeder::class);
});

it('imports config and links with encrypted keys, counts the caches, leaves no trace on a dry run and repeats', function () {
    $db = LegacyDatabase::connection();
    foreach (['intra_federation_sync_log', 'intra_federation_cache_personnel', 'intra_federation_links'] as $t) {
        $db->table($t)->delete();
    }
    $id = '4b0f7c1e-7a51-4d6e-9a43-1d2f3c4b5a69';
    LegacySeed::config(['FEDERATION_ENABLED' => '1', 'FEDERATION_INSTANCE_ID' => $id, 'FEDERATION_INSTANCE_NAME' => 'Wache Nord']);
    $out = str_repeat('a', 64);
    $in = str_repeat('b', 64);
    $db->table('intra_federation_links')->insert(['id' => 5, 'instance_id' => '9e2d1c0b-1111-4222-8333-444455556666', 'instance_name' => 'Wache Süd',
        'instance_url' => 'https://sued.example/', 'api_key_outgoing' => $out, 'api_key_incoming' => $in, 'consume_personnel' => 1, 'provide_fire' => 1,
        'sync_interval_minutes' => 2, 'last_sync_status' => 'error', 'last_sync_error' => 'Antwort: <html>geheim', 'is_active' => 1]);
    $db->table('intra_federation_cache_personnel')->insert(['source_instance_id' => '9e2d1c0b-1111-4222-8333-444455556666', 'remote_id' => 1, 'cached_at' => '2026-07-15 10:00:00']);

    $dry = LegacySeed::run(['federation'], dry: true);
    expect($dry['exit'])->toBe(0)->and(DB::table('federation_links')->count())->toBe(0);

    $report = LegacySeed::run(['federation'], options: ['--overwrite-settings' => true]);
    $report2 = LegacySeed::run(['federation']);

    $settings = app(Settings::class);
    $settings->refresh();
    $link = FederationLink::query()->sole();
    $raw = DB::table('federation_links')->first();
    expect($report['exit'])->toBe(0)->and($report2['exit'])->toBe(0)
        ->and($settings->get('federation.instance_id'))->toBe($id)
        ->and($settings->get('federation.instance_name'))->toBe('Wache Nord')
        ->and($settings->get('modules.federation.enabled'))->toBeTrue()
        ->and($raw->secret_outgoing)->not->toContain($out)->and($raw->secret_incoming)->not->toContain($in)
        ->and($link)->secret_outgoing->toBe($out)->secret_incoming->toBe($in)->url->toBe('https://sued.example')
        ->consume_personnel->toBeTrue()->provide_fire->toBeTrue()->provide_personnel->toBeFalse()
        ->sync_interval_minutes->toBe(5)->confirmed_at->toBeNull()->last_sync_status->toBe('pending')->last_sync_error->toBeNull()
        ->and(json_encode($report, JSON_UNESCAPED_UNICODE))->toContain('Wache Süd')->toContain('intra_federation_cache_personnel: 1')->not->toContain($out)->not->toContain($in);
});
