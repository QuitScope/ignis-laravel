<?php

use App\Auth\ApiKey;
use App\Models\FiveMCharacter;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\FireTab\Models\FireIncident;

uses(RefreshDatabase::class);

require_once __DIR__.'/Emd/emdHelpers.php';

dataset('p10_callers', [
    'no key' => ['none', [403, 403, 403, 410, 410]],
    'wrong key' => ['wrong', [403, 403, 403, 410, 410]],
    'no key configured' => ['unset', [503, 503, 503, 410, 410]],
    'browser account' => ['account', [403, 403, 403, 410, 410]],
    'valid key' => ['valid', [200, 200, 422, 410, 410]],
]);

it('answers each caller as the matrix says and a refused call writes nothing', function (string $caller, array $codes) {
    emd_vehicles();
    $token = 'token-of-a-page-0123456789';
    (new FiveMCharacter)->forceFill(['user_id' => User::factory()->create()->id, 'token_hash' => FiveMCharacter::hashToken($token), 'expires_at' => now()->addMinutes(10)])->save();
    $key = app(ApiKey::class)->current();
    $sent = ['none' => [], 'account' => [], 'wrong' => ['intraRP_API_Key' => 'wrong'], 'unset' => ['intraRP_API_Key' => $key], 'valid' => ['intraRP_API_Key' => $key]][$caller];
    $sync = $sent + array_diff_key(emd_fixture(), ['intraRP_API_Key' => true]);
    // A refused call carries a full identify (it would link the token); the valid key sends only itself: ef_bridge's connection test.
    $identify = $caller === 'valid' ? $sent : $sent + ['session_id' => $token, 'char_name' => 'Max Muster', 'char_job' => 'ambulance'];
    if ($caller === 'unset') {
        app(Settings::class)->forget('api.key');
    }
    if ($caller === 'account') {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
    }

    $got = [
        emd_sync($sync)->status(),
        emd_sync($sync, '/api/emd-sync')->status(),
        $this->postJson('/api/character/identify.php', $identify)->status(),
        $this->getJson('/api/character/get-session-id')->status(),
        $this->postJson('/api/character/get-session-id.php', ['intraRP_API_Key' => $key])->status(),
    ];

    expect($got)->toBe($codes);
    $written = $caller === 'valid';
    expect(EnotfProtocol::query()->exists())->toBe($written)
        ->and(FireIncident::query()->exists())->toBe($written)
        ->and(app(Settings::class)->get('emd.last_sync_at') !== null)->toBe($written)
        ->and(FiveMCharacter::query()->whereNotNull('identified_at')->exists())->toBeFalse();
})->with('p10_callers');
