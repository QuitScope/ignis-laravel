<?php

use App\Models\RegistrationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reserves a code exactly once', function () {
    $c = RegistrationCode::generate(null, 'x', null);

    expect(RegistrationCode::reserve($c->code)?->is($c))->toBeTrue()
        ->and(RegistrationCode::reserve($c->code))->toBeNull()
        ->and($c->fresh()->isRedeemable())->toBeFalse();
});

it('does not reserve expired or unknown codes', function () {
    $c = RegistrationCode::generate(null, null, now()->subSecond());

    expect(RegistrationCode::reserve($c->code))->toBeNull()
        ->and(RegistrationCode::reserve('nope'))->toBeNull()
        ->and($c->isRedeemable())->toBeFalse();
});

it('generates 32 character codes and records the redeemer', function () {
    $by = User::factory()->create();
    $c = RegistrationCode::generate($by, null, now()->addDay());
    $new = User::factory()->create();
    $c->redeemFor($new);

    expect(strlen($c->code))->toBe(32)->and($c->isRedeemable())->toBeTrue()
        ->and($c->fresh()->used_by)->toBe($new->id);
});

it('reserves with exactly one conditional UPDATE and no preceding SELECT', function () {
    $c = RegistrationCode::generate(null, null, now()->addDay());
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = str_replace(['"', '`'], '', strtolower($q->sql));
    });

    RegistrationCode::reserve($c->code);

    $writes = array_values(array_filter($queries, fn ($q) => ! str_starts_with($q, 'select')));
    expect($writes)->toHaveCount(1)
        ->and($writes[0])->toStartWith('update registration_codes')
        ->and($writes[0])->toContain('used_at is null')
        ->and($writes[0])->toContain('expires_at is null or expires_at >')
        ->and($queries[0])->toBe($writes[0]);
});

it('does not reserve a used code again', function () {
    $c = RegistrationCode::generate(null, null, null);
    RegistrationCode::reserve($c->code);

    expect(RegistrationCode::reserve($c->code))->toBeNull();
});
