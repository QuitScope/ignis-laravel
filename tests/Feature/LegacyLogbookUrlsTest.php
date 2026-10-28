<?php

use App\Http\LegacyUrlMap;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects the old logbook pages to /logbook', function () {
    foreach (['/logbook/index', '/logbook/index.php', '/fahrtenbuch', '/fahrtenbuch/index.php'] as $from) {
        $this->get($from)->assertStatus(301)->assertRedirect('/logbook');
    }
});

it('redirects the old logbook root with its trailing slash', function () {
    expect(LegacyUrlMap::redirectFor('/logbook/'))->toBe(['/logbook', 301]);
});

it('keeps the query when redirecting', function () {
    $this->get('/logbook/index?vehicle=3')->assertStatus(301)->assertRedirect('/logbook?vehicle=3');
});

it('has no POST /logbook/actions any more', function () {
    $this->post('/logbook/actions', ['action' => 'create'])->assertNotFound();
});
