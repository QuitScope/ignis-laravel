<?php

use App\Update\Maintenance;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\MaintenanceSandbox;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(fn () => MaintenanceSandbox::enter());

it('maintenance enable/disable idempotent', function () {
    $m = new Maintenance;
    $m->enable();
    $m->enable();
    expect($m->isActive())->toBeTrue();
    $m->disable();
    $m->disable();
    expect($m->isActive())->toBeFalse();
});

it('enable throws when the down command fails', function () {
    Artisan::shouldReceive('call')->once()->andReturn(1);
    expect(fn () => (new Maintenance)->enable())->toThrow(RuntimeException::class);
});

it('disable throws when up reports success but the file stays', function () {
    $m = new Maintenance;
    $m->enable();
    Artisan::shouldReceive('call')->once()->andReturn(0);
    expect(fn () => $m->disable())->toThrow(RuntimeException::class);
});
