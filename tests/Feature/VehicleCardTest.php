<?php

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDefect;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the card to any signed-in account, privately cacheable for 60 seconds', function () {
    $v = Vehicle::factory()->create(['name' => 'Florian 1', 'identifier' => 'hlf_1', 'type' => 'HLF', 'service' => Vehicle::SERVICE_FIRE, 'license_plate' => 'HH-1']);

    $res = $this->actingAs(User::factory()->create())->getJson("/api/vehicles/{$v->id}/card");

    $res->assertOk()->assertExactJson([
        'id' => $v->id, 'name' => 'Florian 1', 'identifier' => 'hlf_1', 'type' => 'HLF',
        'service_label' => 'Feuerwehr', 'license_plate' => 'HH-1',
        'state' => 'operable', 'blocking_defects' => 0, 'info_defects' => 0,
    ]);
    expect($res->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=60');
});

it('derives the state from the admin switch and the open defects', function () {
    $u = User::factory()->create();
    $blocked = Vehicle::factory()->create();
    VehicleDefect::factory()->for($blocked)->blocking()->create();
    VehicleDefect::factory()->for($blocked)->count(2)->create();
    VehicleDefect::factory()->for($blocked)->blocking()->resolved()->create();
    $off = Vehicle::factory()->inactive()->create();
    VehicleDefect::factory()->for($off)->blocking()->resolved()->create();

    $this->actingAs($u)->getJson("/api/vehicles/{$blocked->id}/card")
        ->assertJson(['state' => 'blocked', 'blocking_defects' => 1, 'info_defects' => 2]);
    $this->actingAs($u)->getJson("/api/vehicles/{$off->id}/card")
        ->assertJson(['state' => 'inactive', 'blocking_defects' => 0, 'info_defects' => 0]);
});

it('requires a login', function () {
    $v = Vehicle::factory()->create();
    $this->getJson("/api/vehicles/{$v->id}/card")->assertUnauthorized();
});

it('answers missing, malformed and overflowing ids with the personnel card 404 and no cache header', function () {
    $u = User::factory()->create();
    foreach (['999', 'abc', '0x1', '99999999999999999999', '1%20OR%201'] as $id) {
        $res = $this->actingAs($u)->getJson("/api/vehicles/{$id}/card");
        $res->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        expect($res->headers->get('Cache-Control'))->not->toContain('max-age=60');
    }
});
