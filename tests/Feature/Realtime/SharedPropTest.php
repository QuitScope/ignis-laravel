<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shares realtime connection values only when reverb is on, only signed in, never the secret', function () {
    $user = User::factory()->create();
    config(['app.url' => 'https://ignis.example', 'broadcasting.default' => 'null']);
    $this->actingAs($user)->get('/')->assertInertia(fn (Assert $p) => $p->where('realtime', null));

    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'pub-key', 'broadcasting.connections.reverb.secret' => 'top-secret']);
    $r = $this->actingAs($user)->get('/')->assertInertia(fn (Assert $p) => $p->where('realtime', [
        'key' => 'pub-key', 'host' => 'ignis.example', 'port' => 443, 'scheme' => 'https', 'path' => '',
    ]));
    expect($r->getContent())->not->toContain('top-secret');

    auth()->logout();
    $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('realtime', null));
});
