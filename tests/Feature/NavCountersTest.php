<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Navigation\NavigationCounters;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->calls = 0;
    app(NavigationCounters::class)->register('mvc', function (User $u) {
        $this->calls++;

        return 3;
    }, module: 'manvboard');
});

it('shares the counters of switched-on modules with signed-in accounts, one evaluation per page', function () {
    $this->actingAs(User::factory()->create())->get('/')->assertInertia(fn (Assert $p) => $p->where('nav.counters', ['mvc' => 3]));

    expect($this->calls)->toBe(1);
});

it('leaves out counters of switched-off modules without asking them', function () {
    app(Settings::class)->set('modules.manvboard.enabled', false);

    $this->actingAs(User::factory()->create())->get('/')->assertInertia(fn (Assert $p) => $p->where('nav.counters', []));
    expect($this->calls)->toBe(0);
});

it('computes nothing for guests', function () {
    $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('nav.counters', []));

    expect($this->calls)->toBe(0)
        ->and(app(NavigationCounters::class)->for(null))->toBe([]);
});

it('leaves out a counter whose resolver answers null (not visible for the account)', function () {
    app(NavigationCounters::class)->register('hidden', fn (User $u) => null, module: 'manvboard');

    $this->actingAs(User::factory()->create())->get('/')->assertInertia(fn (Assert $p) => $p->where('nav.counters', ['mvc' => 3]));
});

it('is lazy: neither a partial reload nor a non-Inertia request computes it', function () {
    $user = User::factory()->create();
    $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'Home', 'X-Inertia-Partial-Data' => 'auth'];

    $this->actingAs($user)->get('/', $headers)->assertOk();
    $this->actingAs($user)->postJson('/api/notifications/mark-all-read')->assertOk();

    expect($this->calls)->toBe(0);
});

it('a failing counter is logged and left out, the page and the other counters stay', function () {
    app(NavigationCounters::class)->register('broken', fn (User $u) => throw new RuntimeException('secret sql'), module: 'manvboard');
    Log::spy();

    $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->where('nav.counters', ['mvc' => 3]));

    Log::shouldHaveReceived('error')->withArgs(fn (string $msg) => str_contains($msg, 'broken'))->once();
});

it('rides along in the bell poll (one request, both numbers)', function () {
    $this->actingAs(User::factory()->create())->getJson('/api/notifications/poll')->assertOk()->assertJson(['navCounters' => ['mvc' => 3]]);
    app(Settings::class)->set('modules.manvboard.enabled', false);
    $this->getJson('/api/notifications/poll')->assertOk()->assertJsonPath('navCounters', []);
});
