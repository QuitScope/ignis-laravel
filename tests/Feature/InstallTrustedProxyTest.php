<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('honours X-Forwarded-Proto when proxies are trusted', function () {
    config(['trustedproxy.proxies' => '*']);

    $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('/')
        ->assertRedirect(preg_replace('#^http:#', 'https:', rtrim(config('app.url'), '/')).'/install');
});

it('ignores X-Forwarded-Proto without trusted proxies', function () {
    $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('/')
        ->assertRedirect(rtrim(config('app.url'), '/').'/install');
});

it('reads the trusted proxies from TRUSTED_PROXIES via config/trustedproxy.php', function () {
    expect(config('trustedproxy'))->toBeArray()->toHaveKey('proxies');
});
