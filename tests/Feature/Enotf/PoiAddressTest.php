<?php

use App\Models\Personnel;
use App\Models\Poi;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Support\ConditionsService;
use Modules\Enotf\Support\ProtocolValues;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function pa_crew(): Vehicle
{
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS]);
    $user = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    test()->actingAs($user)->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => $vehicle->id])->assertRedirect('/enotf/overview');

    return $vehicle;
}

function pa_protocol(array $over = []): EnotfProtocol
{
    $p = (new EnotfProtocol)->forceFill($over + ['enr' => (string) random_int(100000, 999999)]);
    $p->save();

    return $p->fresh();
}

it('searches active POIs: two characters minimum, at most 20, inactive hidden, crew only', function () {
    Poi::factory()->count(25)->create(['name' => 'Klinik Süd', 'type' => 'Klinik']);
    Poi::factory()->create(['name' => 'Klinik Alt', 'is_active' => false]);
    Poi::factory()->create(['name' => '100% Wache', 'legacy_identifier' => 'w_1']);

    $this->getJson('/enotf/pois/search?q=Kl')->assertUnauthorized();
    pa_crew();
    $this->getJson('/enotf/pois/search?q=K')->assertUnprocessable();
    $this->getJson('/enotf/pois/search?q=Klinik')->assertOk()->assertJsonCount(20, 'data')
        ->assertJsonPath('data.0.name', 'Klinik Süd')->assertJsonStructure(['data' => [['id', 'name', 'type', 'identifier', 'address']]]);
    $this->getJson('/enotf/pois/search?q=0%25')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.identifier', 'w_1');
    $this->getJson('/enotf/pois/search?q=Klinik%20Alt')->assertOk()->assertJsonCount(0, 'data');
});

it('writes addresses through the revisioned merge, with a POI as destination, and reports a stale revision', function () {
    $vehicle = pa_crew();
    $p = pa_protocol(['vehicle_transport_id' => $vehicle->id, 'destination_code' => '21']);
    $poi = Poi::factory()->create(['name' => 'Klinik Nord', 'street' => 'Weg', 'house_number' => '5', 'city' => 'Stadt', 'type' => 'Klinik']);
    $addr = ['strasse' => 'Hauptstr.', 'hnr' => '1', 'ort' => 'Ort', 'ortsteil' => ''];

    $this->putJson("/enotf/p/{$p->enr}/address/transport", ['revision' => 1, 'address' => $addr])
        ->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('applied', ['transp_adresse']);
    $this->putJson("/enotf/p/{$p->enr}/address/transport", ['revision' => 1, 'address' => ['strasse' => 'Neu'] + $addr])
        ->assertOk()->assertJsonPath('conflicts.0.key', 'transp_adresse');

    $this->putJson("/enotf/p/{$p->enr}/address/target", ['revision' => 3, 'poi_id' => $poi->id])->assertOk();
    $p->refresh();
    expect($p->destination_poi_id)->toBe($poi->id)->and($p->destination_code)->toBeNull()
        ->and($p->data)->toMatchArray(['ziel_poi' => 'Klinik Nord', 'ziel_adresse' => ['strasse' => 'Weg', 'hnr' => '5', 'ort' => 'Stadt', 'ortsteil' => null]]);
    $rules = array_column(ConditionsService::evaluate(ProtocolValues::from($p)), 'rule');
    expect($rules)->not->toContain('transportziel')->not->toContain('ziel_adresse');

    $this->getJson("/enotf/p/{$p->enr}/changes?since=3")->assertJsonPath('changes_since.destination_poi_id', $poi->id);

    $this->patchJson("/enotf/p/{$p->enr}", ['revision' => 4, 'fields' => ['transportziel' => 2]])->assertOk()
        ->assertJsonPath('applied', ['transportziel', 'ziel_poi', 'ziel_adresse']);
    expect($p->fresh()->destination_poi_id)->toBeNull()->and($p->fresh()->data)->not->toHaveKeys(['ziel_poi', 'ziel_adresse'])
        ->and($p->fresh()->field_revisions)->toMatchArray(['ziel_poi' => 5, 'ziel_adresse' => 5]);
    expect($this->getJson("/enotf/p/{$p->enr}/changes?since=4")->json('changes_since'))
        ->toEqual(['transportziel' => 2, 'ziel_poi' => null, 'ziel_adresse' => null, 'destination_poi_id' => null]);

    $off = Poi::factory()->create(['is_active' => false]);
    $this->putJson("/enotf/p/{$p->enr}/address/target", ['revision' => 5, 'poi_id' => $off->id])->assertUnprocessable();
    $this->putJson("/enotf/p/{$p->enr}/address/home", ['revision' => 5, 'address' => $addr])->assertNotFound();
    $p->forceFill(['status' => 'released'])->save();
    $this->putJson("/enotf/p/{$p->enr}/address/transport", ['revision' => 5, 'address' => $addr])->assertStatus(409);
});

it('refuses addresses of a protocol of another vehicle', function () {
    pa_crew();
    $foreign = pa_protocol(['vehicle_transport_id' => Vehicle::factory()->create()->id]);

    $this->putJson("/enotf/p/{$foreign->enr}/address/transport", ['revision' => 1, 'address' => ['ort' => 'X']])->assertForbidden();
    expect($foreign->fresh()->revision)->toBe(1);
});
