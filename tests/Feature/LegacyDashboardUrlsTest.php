<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps the legacy query string when redirecting /notifications to the inbox', function () {
    $this->get('/benachrichtigungen/index.php?x=1')->assertStatus(301)->assertRedirect('/inbox?x=1');
});

it('answers the dropped legacy dashboard, announcement and telemetry endpoints with 404', function () {
    foreach ([
        ['POST', '/settings/dashboard/categories/create'],
        ['POST', '/settings/dashboard/tiles/update'],
        ['POST', '/settings/dashboard/tiles/delete'],
        ['GET', '/api/telemetry/background?action=refresh-announcements'],
        ['POST', '/api/telemetry/background'],
    ] as [$method, $path]) {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]))->call($method, $path)->assertNotFound();
    }
});
