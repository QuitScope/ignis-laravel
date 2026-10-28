<?php

use App\Events\VehicleDefectReported;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDefect;
use App\Models\VehicleDefectEvent;
use App\Vehicles\DefectReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('records a crew report without an account under the given name', function () {
    $v = Vehicle::factory()->create(['is_active' => true]);

    $d = (new DefectReporter)->report($v, ['title' => 'Trage klemmt', 'category' => 'fit_out', 'is_operable' => false], null, 'Erika Crew');

    expect($d->reported_by)->toBeNull()->and($d->reporter_name)->toBe('Erika Crew')->and($d->is_operable)->toBeFalse()
        ->and($d->category)->toBe('fit_out')->and($d->status)->toBe('open');
    $e = VehicleDefectEvent::sole();
    expect($e->action)->toBe('created')->and($e->user_id)->toBeNull()
        ->and($e->details)->toBe('Defekt gemeldet: Trage klemmt (Gemeldet durch: Erika Crew)');
    expect($v->fresh()->is_active)->toBeTrue()->and($v->fresh()->is_operable)->toBeFalse();
});

it('leaves the name out of the text when an account reports', function () {
    $u = User::factory()->create();
    $d = (new DefectReporter)->report(Vehicle::factory()->create(), ['title' => 'A'], $u, 'Ignoriert');

    expect($d->reported_by)->toBe($u->id)->and($d->reporter_name)->toBeNull()->and($d->is_operable)->toBeTrue();
    expect(VehicleDefectEvent::sole()->only(['user_id', 'details']))->toBe(['user_id' => $u->id, 'details' => 'Defekt gemeldet: A']);
});

it('stores unknown categories as other', function () {
    $d = (new DefectReporter)->report(Vehicle::factory()->create(), ['title' => 'A', 'category' => 'sonstiges'], null);
    expect($d->category)->toBe('other');
});

it('fires the event only after the surrounding transaction commits, and not on rollback', function () {
    $levels = [];
    Event::listen(VehicleDefectReported::class, function () use (&$levels) {
        $levels[] = DB::transactionLevel();
    });
    $v = Vehicle::factory()->create();
    $base = DB::transactionLevel();

    DB::transaction(function () use ($v, &$levels) {
        (new DefectReporter)->report($v, ['title' => 'A'], null);
        expect($levels)->toBe([]);
    });
    expect($levels)->toBe([$base]);

    try {
        DB::transaction(function () use ($v) {
            (new DefectReporter)->report($v, ['title' => 'B'], null);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect($levels)->toBe([$base])->and(VehicleDefect::count())->toBe(1);
});
