<?php

use App\Auth\ApiKey;
use App\Models\Personnel;
use App\Models\TabletLoginToken;
use App\Models\User;
use App\Personnel\AccountLink;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const FALLBACK_DISCORD_ID = '123456789012345679';

beforeEach(function () {
    app(Settings::class)->set('auth.tablet_login', true);
});

function fallbackIssue(): TestResponse
{
    return test()->postJson('/api/tablet/login-token', ['discord_id' => FALLBACK_DISCORD_ID], ['X-API-Key' => app(ApiKey::class)->current()]);
}

it('finds the account through the record that carries the Discord ID', function () {
    $user = User::factory()->create(['discord_id' => '999999999999999999']);
    AccountLink::link($user, Personnel::factory()->create(['discord_id' => FALLBACK_DISCORD_ID]), null);

    fallbackIssue()->assertOk()->assertJsonPath('success', true);
    expect(TabletLoginToken::sole()->user_id)->toBe($user->id);
});

it('falls back when the account with the Discord ID is inactive', function () {
    User::factory()->inactive()->create(['discord_id' => FALLBACK_DISCORD_ID]);
    $user = User::factory()->create();
    AccountLink::link($user, Personnel::factory()->create(['discord_id' => FALLBACK_DISCORD_ID]), null);

    fallbackIssue()->assertOk();
    expect(TabletLoginToken::sole()->user_id)->toBe($user->id);
});

it('prefers the account with the Discord ID over the record', function () {
    $own = User::factory()->create(['discord_id' => FALLBACK_DISCORD_ID]);
    AccountLink::link(User::factory()->create(), Personnel::factory()->create(['discord_id' => FALLBACK_DISCORD_ID]), null);
    Personnel::factory()->create(['discord_id' => FALLBACK_DISCORD_ID]);

    fallbackIssue()->assertOk();
    expect(TabletLoginToken::sole()->user_id)->toBe($own->id);
});

it('answers 409 ambiguous_user for two records with the Discord ID', function () {
    AccountLink::link(User::factory()->create(), Personnel::factory()->create(['discord_id' => FALLBACK_DISCORD_ID]), null);
    Personnel::factory()->create(['discord_id' => FALLBACK_DISCORD_ID]);

    $r = fallbackIssue()->assertStatus(409)->assertExactJson(['success' => false, 'error' => 'ambiguous_user']);
    expect($r->headers->get('Cache-Control'))->toContain('no-store')->and(TabletLoginToken::count())->toBe(0);
});

it('answers 404 unknown_user for a record without account or with an inactive one', function () {
    $person = Personnel::factory()->create(['discord_id' => FALLBACK_DISCORD_ID]);
    fallbackIssue()->assertNotFound()->assertExactJson(['success' => false, 'error' => 'unknown_user']);

    AccountLink::link(User::factory()->inactive()->create(), $person, null);
    fallbackIssue()->assertNotFound()->assertExactJson(['success' => false, 'error' => 'unknown_user']);
    expect(TabletLoginToken::count())->toBe(0);
});

it('matches the 409 branch ef_bridge expects (contract fixture)', function () {
    $lua = base_path('../ef_bridge/tests/tablet_login_check.lua');
    $main = base_path('../ef_bridge/server/main.lua');
    if (! is_file($lua) || ! is_file($main)) {
        $this->markTestSkipped('ef_bridge checkout not next to this project');
    }

    // ef_bridge feeds this exact answer into its handler and expects the "several accounts" message.
    expect(file_get_contents($lua))->toContain('run(1, 409, "ambiguous", { success = false, error = "ambiguous_user" })')
        ->and(file_get_contents($main))->toContain("statusCode == 409 and data.error == 'ambiguous_user'");

    Personnel::factory()->count(2)->create(['discord_id' => FALLBACK_DISCORD_ID]);
    expect(fallbackIssue()->assertStatus(409)->json())->toBe(['success' => false, 'error' => 'ambiguous_user']);
});
