<?php

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\FireTab\Enums\SitrepSource;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireSitrep;
use Modules\FireTab\Support\FireSitreps;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('dedupes dispatch sitreps within ±15 min and stores them as dispatch, synced', function () {
    $incident = (new FireIncident)->forceFill(['number' => 'E-1']);
    $incident->save();
    $at = CarbonImmutable::parse('2026-07-01 12:00:00', 'UTC');

    $s = FireSitreps::importDispatchSitrep($incident, ' Feuer aus ', $at, true);
    expect($s->source)->toBe(SitrepSource::Dispatch)->and($s->is_important)->toBeTrue()->and($s->synced_at)->not->toBeNull()
        ->and(FireSitreps::importDispatchSitrep($incident, 'Feuer aus', $at->addMinutes(15), false))->toBeNull()
        ->and(FireSitreps::importDispatchSitrep($incident, 'Feuer aus', $at->subMinutes(14), false))->toBeNull()
        ->and(FireSitreps::importDispatchSitrep($incident, 'Feuer aus', $at->addMinutes(16), false))->not->toBeNull()
        ->and(FireSitreps::importDispatchSitrep($incident, 'Nachlöschen', $at, false))->not->toBeNull()
        ->and(FireSitreps::importDispatchSitrep($incident, '  ', $at, false))->toBeNull();
});

it('returns only unsynced local sitreps and marks only the fetched ids', function () {
    $incident = (new FireIncident)->forceFill(['number' => 'E-2']);
    $incident->save();
    $make = fn (array $a) => tap((new FireSitrep)->forceFill(['fire_incident_id' => $incident->id, 'reported_at' => now(), 'text' => 'x', ...$a]))->save();
    $make(['source' => SitrepSource::Local]);
    $make(['source' => SitrepSource::Local, 'synced_at' => now()]);
    $make(['source' => SitrepSource::Dispatch]);

    $ids = DB::transaction(function () use ($incident) {
        $ids = FireSitreps::pendingFor($incident)->pluck('id')->all();
        FireSitreps::markSynced($ids);

        return $ids;
    });
    $late = $make(['source' => SitrepSource::Local]);

    expect($ids)->toHaveCount(1)->and(FireSitreps::pendingFor($incident)->pluck('id')->all())->toBe([$late->id]);
});

it('refuses pendingFor outside a transaction (it must share one with markSynced)', function () {
    DB::rollBack();
    try {
        expect(fn () => FireSitreps::pendingFor(new FireIncident))->toThrow(LogicException::class);
    } finally {
        DB::beginTransaction();
    }
});
