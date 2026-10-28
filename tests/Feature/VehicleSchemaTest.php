<?php

use App\Models\LoadoutCategory;
use App\Models\LoadoutItem;
use App\Models\Poi;
use App\Models\TacticalSymbolTemplate;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDefect;
use App\Models\VehicleDefectEvent;
use App\Models\VehicleRegistryEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the vehicle tables and columns', function () {
    expect(Schema::getColumnListing('vehicles'))->toEqualCanonicalizing(['id', 'sort_order', 'identifier', 'name', 'type', 'service', 'license_plate', 'is_active', 'allowed_jobs', 'station_poi_id', 'tactical_symbol', 'tactical_symbol_name', 'fms_status', 'fms_status_at', 'fms_status_source', 'created_at', 'updated_at'])
        ->and(Schema::getColumnListing('vehicle_defects'))->toEqualCanonicalizing(['id', 'vehicle_id', 'title', 'description', 'category', 'is_operable', 'status', 'reported_by', 'reporter_name', 'assigned_to', 'resolved_by', 'resolved_at', 'resolution_note', 'created_at', 'updated_at'])
        ->and(Schema::getColumnListing('vehicle_defect_events'))->toEqualCanonicalizing(['id', 'vehicle_defect_id', 'user_id', 'action', 'details', 'created_at', 'legacy_id'])
        ->and(Schema::getColumnListing('pois'))->toEqualCanonicalizing(['id', 'name', 'street', 'house_number', 'city', 'district', 'type', 'is_active', 'legacy_identifier', 'created_at', 'updated_at'])
        ->and(Schema::hasTable('loadout_categories'))->toBeTrue()
        ->and(Schema::hasTable('loadout_items'))->toBeTrue()
        ->and(Schema::hasTable('tactical_symbol_templates'))->toBeTrue()
        ->and(Schema::hasTable('vehicle_registry_entries'))->toBeTrue();
});

it('has a model factory for every table', function () {
    foreach ([Poi::class, Vehicle::class, VehicleDefect::class, VehicleDefectEvent::class, LoadoutCategory::class, LoadoutItem::class, TacticalSymbolTemplate::class, VehicleRegistryEntry::class] as $model) {
        expect($model::factory()->create()->exists)->toBeTrue();
    }
});

it('keeps the vehicle active while a blocking defect makes it inoperable', function () {
    $v = Vehicle::factory()->create();
    VehicleDefect::factory()->blocking()->for($v)->create();

    expect(Vehicle::operable()->count())->toBe(0)
        ->and(Vehicle::active()->count())->toBe(1)
        ->and($v->fresh()->is_operable)->toBeFalse()
        ->and($v->fresh()->is_active)->toBeTrue();
});

it('treats a hand-deactivated vehicle without defects as not operable', function () {
    $v = Vehicle::factory()->inactive()->create();

    expect(Vehicle::operable()->count())->toBe(0)
        ->and($v->is_operable)->toBeFalse();
});

it('ignores informational and resolved defects, and resolving switches nothing', function () {
    $v = Vehicle::factory()->create();
    VehicleDefect::factory()->for($v)->create(['is_operable' => true]);
    $blocking = VehicleDefect::factory()->blocking()->for($v)->create();

    expect($v->fresh()->is_operable)->toBeFalse();

    $blocking->update(['status' => VehicleDefect::RESOLVED]);

    expect($v->fresh()->is_operable)->toBeTrue()
        ->and($v->fresh()->is_active)->toBeTrue()
        ->and(VehicleDefect::blocking()->count())->toBe(0)
        ->and(Vehicle::operable()->count())->toBe(1);

    $off = Vehicle::factory()->inactive()->create();
    VehicleDefect::factory()->blocking()->for($off)->create()->update(['status' => VehicleDefect::RESOLVED]);

    expect($off->fresh()->is_active)->toBeFalse()->and($off->fresh()->is_operable)->toBeFalse();
});

it('uses a loaded blocking count instead of querying again', function () {
    $v = Vehicle::factory()->create();
    VehicleDefect::factory()->blocking()->count(2)->for($v)->create();
    VehicleDefect::factory()->for($v)->create();

    $loaded = Vehicle::withBlockingDefectsCount()->find($v->id);
    DB::enableQueryLog();

    expect($loaded->blocking_defects_count)->toBe(2)->and($loaded->is_operable)->toBeFalse()
        ->and(DB::getQueryLog())->toBeEmpty()
        ->and($v->fresh()->blocking_defects_count)->toBe(2);
});

it('labels FMS statuses and falls back for unknown ones', function () {
    expect(Vehicle::fmsLabel('3'))->toBe(Vehicle::FMS_LABELS[3])
        ->and(Vehicle::fmsLabel('7'))->toBe('Status 7')
        ->and(Vehicle::fmsLabel(null))->toBeNull()
        ->and(Vehicle::FMS_LABELS)->toHaveCount(7)
        ->and(VehicleDefect::CATEGORIES)->toHaveCount(14)->toContain('windscreen', 'other')
        ->and(Poi::STATION_TYPES)->toBe(['Rettungswache', 'Feuerwache']);
});

it('stores allowed jobs as a json list, null meaning all', function () {
    $v = Vehicle::factory()->create(['allowed_jobs' => ['ems', 'fire']]);

    expect($v->fresh()->allowed_jobs)->toBe(['ems', 'fire'])
        ->and(Vehicle::factory()->create()->fresh()->allowed_jobs)->toBeNull();
});

it('nulls the station when its poi is deleted', function () {
    $poi = Poi::factory()->create();
    $v = Vehicle::factory()->create(['station_poi_id' => $poi->id]);

    expect($v->station->is($poi))->toBeTrue();
    $poi->delete();

    expect($v->fresh()->station_poi_id)->toBeNull()->and($v->fresh()->station)->toBeNull();
});

it('cascades vehicle deletion to defects and events', function () {
    $d = VehicleDefect::factory()->create();
    VehicleDefectEvent::factory()->for($d, 'defect')->create();
    $d->vehicle->delete();

    expect(VehicleDefect::count())->toBe(0)->and(VehicleDefectEvent::count())->toBe(0);
});

it('nulls user references when the account is deleted', function () {
    $u = User::factory()->create();
    $d = VehicleDefect::factory()->create(['reported_by' => $u->id, 'assigned_to' => $u->id, 'resolved_by' => $u->id]);
    $u->delete();

    expect($d->fresh()->only(['reported_by', 'assigned_to', 'resolved_by']))->toBe(['reported_by' => null, 'assigned_to' => null, 'resolved_by' => null]);
});

it('cascades loadout categories to items', function () {
    $c = LoadoutCategory::factory()->has(LoadoutItem::factory()->count(2), 'items')->create();
    $c->delete();

    expect(LoadoutItem::count())->toBe(0);
});
