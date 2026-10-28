<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('maps the legacy .php and query forms of the system pages', function () {
    foreach ([
        ['/settings/index.php', 301, '/settings'],
        ['/settings/system/index.php?x=1', 301, '/settings/system?x=1'],
        ['/settings/system/plugins.php', 301, '/settings/system/modules'],
        ['/settings/system/performance?tab=db', 301, '/settings/system?tab=db'],
        ['/settings/organisation', 301, '/settings/system/config?tab=organisation'],
    ] as [$from, $status, $to]) {
        $this->get($from)->assertStatus($status)->assertRedirect($to);
    }
});

it('keeps /settings/system/config on its own path for GET', function () {
    $this->get('/settings/system/config.php')->assertRedirect('/login');
    $this->actingAs(User::factory()->create(['is_super_admin' => true]))->get('/settings/system/config.php?tab=general')->assertOk();
});

it('shows the settings overview to any signed-in account (tiles by right, client side)', function () {
    $this->get('/settings')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get('/settings')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Settings/Index'));
});

it('answers the dropped legacy system endpoints with 404', function () {
    foreach ([
        ['POST', '/api/system/regenerate-api-key'],
        ['GET', '/api/system/regenerate-api-key'],
        ['GET', '/settings/system/regenerate-api-key'],
        ['POST', '/settings/system/regenerate-api-key'],
        ['DELETE', '/settings/system/regenerate-api-key'],
        ['POST', '/api/system/logo'],
        ['POST', '/api/system/logo/remove'],
        ['GET', '/api/system/performance'],
        ['GET', '/assets/functions/system/performance-api'],
        ['GET', '/api/system/theme'],
        ['POST', '/api/system/theme'],
        ['POST', '/assets/functions/system/theme-api'],
        ['GET', '/api/system/composer-status'],
        ['POST', '/api/system/composer-status'],
        ['GET', '/api/composer-status'],
        ['GET', '/_router/ping'],
        ['GET', '/api/_router/ping'],
        ['GET', '/api/_router/whoami'],
        ['GET', '/plugins/knowledge-base/assets/app.js'],
        ['GET', '/cron.php'],
        ['POST', '/cron.php'],
    ] as [$method, $path]) {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]))->call($method, $path)->assertNotFound();
    }
});

it('keeps the profile theme and discord-dm posts on their own paths', function () {
    foreach (['/profile/theme', '/profile/discord-dm'] as $path) {
        $this->post($path)->assertRedirect('/login');
    }
});
