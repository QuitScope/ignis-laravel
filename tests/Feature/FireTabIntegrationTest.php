<?php

use App\Jobs\SendDiscordWebhook;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentLog;
use Modules\FireTab\Models\FireSitrep;
use Modules\FireTab\Models\FireStatusQueue;
use Modules\Logbook\Models\LogbookEntry;

uses(RefreshDatabase::class);

it('runs the whole flow: crew login, incident, vehicle, sitrep, status, finalize, QM reopen, logbook', function () {
    $this->seed(RoleSeeder::class);
    Queue::fake();
    config(['ignis.timezone' => 'Europe/Berlin']);

    $leader = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $crew = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $own = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE, 'name' => 'HLF 1']);
    $extra = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE, 'name' => 'DLK 1']);

    $this->actingAs($crew)->post('/firetab/login', ['vehicle_id' => $own->id])->assertRedirect('/firetab/list');
    $this->post('/firetab/incidents', [
        'number' => 'E-100', 'location' => 'Hauptstr. 1', 'keyword' => 'B2',
        'started_date' => '2026-07-01', 'started_time' => '14:30', 'leader_personnel_id' => $leader->personnel_id,
    ])->assertRedirect();
    $incident = FireIncident::where('number', 'E-100')->sole();
    $url = "/firetab/incidents/{$incident->id}";
    expect($incident->started_at->format('H:i'))->toBe('12:30');

    $this->post("$url/vehicles", ['vehicle_id' => $extra->id])->assertRedirect();
    $this->post("$url/sitreps", ['date' => '2026-07-01', 'time' => '14:45', 'text' => 'Feuer unter Kontrolle', 'incident_vehicle_id' => $incident->vehicles()->where('vehicle_id', $own->id)->value('id')])->assertRedirect();
    $this->post('/firetab/status', ['status' => '4'])->assertRedirect();

    expect($incident->vehicles()->count())->toBe(2)
        ->and(FireSitrep::sole()->text)->toBe('Feuer unter Kontrolle')
        ->and(FireStatusQueue::sole()->incident_number)->toBe('E-100');

    $this->post("$url/finalize")->assertRedirect();
    $this->post("$url/finalize")->assertStatus(409);
    expect($incident->fresh()->finalized_at)->not->toBeNull()
        ->and($leader->notifications()->count())->toBe(1);
    Queue::assertPushed(SendDiscordWebhook::class, 1);

    $role = Role::create(['name' => 'QM', 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['fire.incident.qm']);
    $qm = User::factory()->withRole($role)->create();
    app('auth')->forgetGuards();
    $this->actingAs($qm)->flushSession()->post("/firetab/qm/{$incident->id}/reopen")->assertRedirect();
    expect($incident->fresh()->finalized_at)->toBeNull()
        ->and(FireIncidentLog::where('fire_incident_id', $incident->id)->pluck('action')->all())->toContain('created');

    app('auth')->forgetGuards();
    $this->actingAs($crew)->post('/firetab/login', ['vehicle_id' => $own->id])->assertRedirect('/firetab/list');
    $this->put("$url/report", ['notes' => 'Nachtrag'])->assertRedirect();
    $key = $this->get('/firetab/logbook')->viewData('page')['props']['formKey'];
    $this->post('/firetab/logbook', ['key' => $key, 'trip_date' => '2026-07-01', 'departed_at' => '14:35', 'arrived_at' => '16:00', 'distance_km' => 6.5, 'trip_type' => 'einsatz', 'reason' => 'E-100'])->assertRedirect('/firetab/logbook');
    expect(LogbookEntry::sole())->vehicle_id->toBe($own->id)->source->toBe('firetab')->created_by->toBe($crew->id)
        ->and($incident->fresh()->notes)->toBe('Nachtrag');
});
