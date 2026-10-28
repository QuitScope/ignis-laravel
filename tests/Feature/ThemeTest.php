<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('keeps guests out', function () {
    $this->post('/profile/theme', ['theme' => 'light'])->assertRedirect('/login');
    $this->postJson('/profile/theme', ['theme' => 'light'])->assertUnauthorized();
    $this->post('/profile/discord-dm', ['discord_dm' => '0'])->assertRedirect('/login');
});

it('stores each allowed theme for the signed-in user only', function () {
    foreach (['dark', 'light', 'system'] as $theme) {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($me)->from('/vehicles')->post('/profile/theme', ['theme' => $theme])->assertRedirect('/vehicles');

        expect($me->fresh()->theme)->toBe($theme)->and($other->fresh()->theme)->toBe('dark');
    }
});

it('answers a fetch with 204', function () {
    $this->actingAs(User::factory()->create())->postJson('/profile/theme', ['theme' => 'light'])->assertNoContent();
});

it('rejects anything but the three lower-case values', function () {
    foreach (['Dark', 'LIGHT', 'auto', '', null, 1, true] as $bad) {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/profile/theme', ['theme' => $bad])->assertUnprocessable()->assertJsonValidationErrors('theme');
        expect($user->fresh()->theme)->toBe('dark');
    }
});

it('rejects arrays and a missing value', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/profile/theme', ['theme' => ['light']])->assertUnprocessable();
    $this->actingAs($user)->postJson('/profile/theme', ['theme' => ['theme' => 'light']])->assertUnprocessable();
    $this->actingAs($user)->postJson('/profile/theme', [])->assertUnprocessable();
    expect($user->fresh()->theme)->toBe('dark');
});

it('names the problem in german and english', function () {
    $user = User::factory()->create();
    $de = $this->actingAs($user)->postJson('/profile/theme', ['theme' => 'x'])->json('errors.theme.0');
    app()->setLocale('en');
    $en = $this->actingAs($user)->postJson('/profile/theme', ['theme' => 'x'])->json('errors.theme.0');

    expect($de)->toContain('Darstellung')->and($en)->toContain('appearance')->and($en)->not->toBe($de);
});

it('ignores a smuggled user id', function () {
    [$me, $other] = [User::factory()->create(), User::factory()->create()];

    $this->actingAs($me)->post('/profile/theme', ['theme' => 'light', 'id' => $other->id, 'user_id' => $other->id]);

    expect($other->fresh()->theme)->toBe('dark');
});

it('is throttled to 30 changes a minute', function () {
    $this->actingAs(User::factory()->create());
    for ($i = 0; $i < 30; $i++) {
        $this->postJson('/profile/theme', ['theme' => 'light'])->assertNoContent();
    }
    $this->postJson('/profile/theme', ['theme' => 'light'])->assertStatus(429);
});

it('shares the stored theme, system for guests, dark for garbage', function () {
    $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('theme', 'system'));

    $user = User::factory()->create(['theme' => 'system']);
    $this->actingAs($user)->get('/')->assertInertia(fn (Assert $p) => $p->where('theme', 'system'));

    DB::table('users')->where('id', $user->id)->update(['theme' => 'neon']);
    $this->actingAs($user->fresh())->get('/')->assertInertia(fn (Assert $p) => $p->where('theme', 'dark'));
});

it('shares system after a logout and the account value again after the next login', function () {
    $user = User::factory()->create(['theme' => 'light']);

    $this->actingAs($user)->get('/')->assertInertia(fn (Assert $p) => $p->where('theme', 'light'));
    $this->post('/logout')->assertRedirect();
    auth()->forgetGuards();
    $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('theme', 'system'));
    $this->actingAs($user->fresh())->get('/')->assertInertia(fn (Assert $p) => $p->where('theme', 'light'));
});

it('renders the theme on <html> before any script runs', function () {
    foreach ([
        'dark' => ['dark', 'dark', 'dark'],
        'light' => ['light', 'light', 'light'],
        'system falls back to dark until the script runs' => ['system', 'system', 'dark'],
        'garbage' => ['"><svg onload=1>', 'dark', 'dark'],
    ] as [$stored, $mode, $attr]) {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['theme' => $stored]);

        $html = $this->actingAs($user->fresh())->get('/')->getContent();

        expect($html)->toContain('data-theme-mode="'.$mode.'"')->toContain(' data-theme="'.$attr.'"')
            ->toContain('prefers-color-scheme: dark')->not->toContain('<svg onload=1>');
    }
});

it('falls back to dark in the inline script when the browser has no matchMedia', function () {
    expect($this->get('/login')->getContent())
        ->toContain("m = !window.matchMedia || matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';");
});

it('pins the sign-in page to dark and sets the skin before any script runs', function () {
    expect($this->get('/login')->getContent())->toContain('data-skin="core"')->toContain('data-theme-mode="system"')
        ->toContain(' data-theme="dark"')->toContain(' data-force-dark')
        ->toContain("if (d.hasAttribute('data-force-dark')) return;");
    expect($this->actingAs(User::factory()->create())->get('/')->getContent())->toContain('data-skin="core"')->not->toContain(' data-force-dark');
});

it('stores discord_dm as a boolean for the signed-in user only', function () {
    [$me, $other] = [User::factory()->create(), User::factory()->create()];

    $this->actingAs($me)->from('/')->post('/profile/discord-dm', ['discord_dm' => '0'])->assertRedirect('/');
    expect($me->fresh()->discord_dm)->toBeFalse()->and($other->fresh()->discord_dm)->toBeTrue();

    $this->actingAs($me)->postJson('/profile/discord-dm', ['discord_dm' => true])->assertNoContent();
    expect($me->fresh()->discord_dm)->toBeTrue();
});

it('rejects a missing or non-boolean discord_dm', function () {
    foreach (['yes', '', null, 2] as $bad) {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/profile/discord-dm', ['discord_dm' => $bad])->assertUnprocessable()->assertJsonValidationErrors('discord_dm');
        expect($user->fresh()->discord_dm)->toBeTrue();
    }
});

it('throttles discord_dm with the same bucket', function () {
    $this->actingAs(User::factory()->create());
    for ($i = 0; $i < 30; $i++) {
        $this->postJson('/profile/discord-dm', ['discord_dm' => 1])->assertNoContent();
    }
    $this->postJson('/profile/discord-dm', ['discord_dm' => 1])->assertStatus(429);
});
