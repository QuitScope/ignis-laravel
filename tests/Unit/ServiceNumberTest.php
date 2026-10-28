<?php

use App\Models\Personnel;
use App\Support\ServiceNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('accepts letters, digits and hyphens with at least one digit', function () {
    foreach ([
        ['BF-042', true], ['7', true], ['-1', true], ['ABC', false], ['', false], ['12 3', false], ['12_3', false], ["12\n", false], ['Ä1', false],
    ] as [$v, $ok]) {
        expect(ServiceNumber::isValid($v))->toBe($ok);
    }
});

it('tells whether a service number is free, optionally ignoring one record', function () {
    $p = Personnel::factory()->create(['service_number' => 'BF-1']);

    expect(ServiceNumber::isAvailable('BF-1'))->toBeFalse()
        ->and(ServiceNumber::isAvailable('BF-1', $p->id))->toBeTrue()
        ->and(ServiceNumber::isAvailable('BF-1', $p->id + 1))->toBeFalse()
        ->and(ServiceNumber::isAvailable('BF-2'))->toBeTrue();
});
