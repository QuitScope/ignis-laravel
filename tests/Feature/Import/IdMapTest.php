<?php

use App\Import\IdMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('stores, reads and overwrites idempotently', function () {
    $map = new IdMap;
    $map->put('role', 7, 1);
    $map->put('role', '7', 2);
    $map->put('user', 7, 9);

    expect($map->get('role', 7))->toBe(2)
        ->and($map->get('user', '7'))->toBe(9)
        ->and($map->get('role', 8))->toBeNull()
        ->and($map->has('role', 7))->toBeTrue()
        ->and($map->has('role', 8))->toBeFalse()
        ->and($map->forEntity('role'))->toBe(['7' => 2])
        ->and(DB::table('legacy_id_map')->count())->toBe(2);
});
