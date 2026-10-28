<?php

use App\Models\Personnel;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serves the versioned /api/v1 card paths like the unversioned ones', function () {
    $v = Vehicle::factory()->create(['name' => 'Florian 1']);
    $person = Personnel::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->getJson("/api/v1/vehicles/{$v->id}/card")->assertOk()->assertJson(['id' => $v->id, 'name' => 'Florian 1']);
    $this->actingAs(User::factory()->create(['is_super_admin' => true]))
        ->getJson("/api/v1/personnel/{$person->id}/card")->assertOk();
});

it('applies the German API segment translation under /api/v1 too, then drops the version', function () {
    $person = Personnel::factory()->create();

    $this->actingAs(User::factory()->create(['is_super_admin' => true]))->getJson("/api/v1/mitarbeiter/{$person->id}/card")->assertOk();
});

it('keeps the login requirement on the versioned card path', function () {
    $v = Vehicle::factory()->create();

    $this->getJson("/api/v1/vehicles/{$v->id}/card")->assertUnauthorized();
});

it('answers every dropped legacy vehicle endpoint with 404', function () {
    foreach ([
        ['GET', '/settings/vehicles/vehicles/12/preview'],
        ['GET', '/settings/vehicles/defects/handler'],
        ['GET', '/api/vehicles/defects-handler'],
        ['GET', '/api/vehicles/import-handler'],
        ['GET', '/api/vehicles/tz-templates'],
        ['GET', '/settings/vehicles/vehload/beladung_handler'],
        ['GET', '/api/v1/vehicles/import-handler'],
    ] as [$method, $path]) {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]))
            ->call($method, $path)->assertNotFound();
    }
});
