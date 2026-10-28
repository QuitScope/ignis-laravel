<?php

/*
 * Prepares a throwaway database for the CEF probe and prints one tablet-login URL per argument.
 * Run against a scratch SQLite file only (DB_DATABASE env), never the real database:
 *   DB_DATABASE=/tmp/cef.sqlite php tests/e2e/cef/setup.php http://127.0.0.1:8123 old new
 * Labels starting with ENOTF sign in an account with a personnel record (`&to=enotf`); the script then also prints
 * VEHICLE (EMS vehicle for probe.mjs --enotf-vehicle) and ENR (an open protocol on that vehicle). Always printed:
 * FIRE_VEHICLE, INCIDENT (FireTab incident with ASÜ trupp and map marker), HOSPITAL and HOSPITAL_CODE (clinic portal).
 * See docs/dev/cef.md.
 */

use App\Install\InstallState;
use App\Models\Personnel;
use App\Models\Poi;
use App\Models\TabletLoginToken;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\HospitalAccessCode;
use Modules\Enotf\Models\HospitalDepartment;
use Modules\FireTab\Models\AsuEvent;
use Modules\FireTab\Models\AsuMember;
use Modules\FireTab\Models\AsuTrupp;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentVehicle;
use Modules\FireTab\Models\FireMapMarker;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_contains((string) config('database.connections.sqlite.database'), 'cef') || config('database.default') !== 'sqlite') {
    fwrite(STDERR, "Refusing: DB_DATABASE must point at a scratch SQLite file whose name contains 'cef'.\n");
    exit(1);
}

Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
$settings = app(Settings::class);
$settings->set(InstallState::KEY, now()->toIso8601String());
$settings->set('auth.tablet_login', true);
$user = User::factory()->create(['is_super_admin' => true, 'name' => 'CEF Probe']);

// eNOTF: a crew needs a personnel record and an EMS vehicle; one open protocol on it.
// Not a super admin: those never write as crew (EnotfProtocolPolicy::crewWrites), the pages would be read only.
$medic = User::factory()->create(['name' => 'CEF Medic', 'personnel_id' => Personnel::factory()->create()->id]);
$vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS]);
$protocol = (new EnotfProtocol)->forceFill([
    'enr' => '990001', 'vehicle_transport_id' => $vehicle->id, 'patient_first_name' => 'Max', 'patient_last_name' => 'Probe',
]);
$protocol->save();

// All tablet modules on; a fire vehicle with one incident (ASÜ trupp, map marker) and a hospital whose
// clinic device signs in with HOSPITAL/HOSPITAL_CODE (probe.mjs --post).
foreach (['firetab', 'enotf', 'logbook', 'federation'] as $module) {
    $settings->set("modules.$module.enabled", true);
}
$fire = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
$incident = (new FireIncident)->forceFill(['location' => 'Hauptstr. 1', 'keyword' => 'B3', 'started_at' => now()]);
$incident->save();
(new FireIncidentVehicle)->forceFill(['fire_incident_id' => $incident->id, 'vehicle_id' => $fire->id, 'name' => $fire->name])->save();
$trupp = (new AsuTrupp)->forceFill(['fire_incident_id' => $incident->id, 'name' => 'AT 1', 'task' => 'Innenangriff', 'vehicle_id' => $fire->id]);
$trupp->save();
(new AsuMember)->forceFill(['trupp_id' => $trupp->id, 'position' => 'tf', 'name' => 'Weber, M'])->save();
(new AsuEvent)->forceFill(['fire_incident_id' => $incident->id, 'trupp_id' => $trupp->id, 'type' => 'angeschlossen', 'bar' => 300,
    'client_uuid' => (string) Str::uuid(), 'device' => $fire->name, 'occurred_at' => now(), 'received_at' => now()])->save();
(new FireMapMarker)->forceFill(['fire_incident_id' => $incident->id, 'marker_type' => FireMapMarker::LOCATION, 'pos_x' => 40, 'pos_y' => 55])->save();
$hospital = Poi::factory()->create(['type' => 'Krankenhaus', 'name' => 'CEF Klinikum', 'is_active' => true]);
(new HospitalDepartment)->forceFill(['poi_id' => $hospital->id, 'name' => 'ZNA', 'created_at' => now()])->save();
(new HospitalAccessCode)->forceFill(['poi_id' => $hospital->id, 'code_hash' => Hash::make('CEFPROBECODE2345'), 'rotated_at' => now()])->save();

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8123', '/');
$enotf = false;
foreach (array_slice($argv, 2) as $label) {
    $enotf = $enotf || str_starts_with($label, 'ENOTF');
    echo str_starts_with($label, 'ENOTF')
        ? $label.'='.$base.'/auth/tablet?token='.TabletLoginToken::issue($medic).'&to=enotf'.PHP_EOL
        : $label.'='.$base.'/auth/tablet?token='.TabletLoginToken::issue($user).PHP_EOL;
}
if ($enotf) {
    echo 'VEHICLE='.$vehicle->id.PHP_EOL.'ENR='.$protocol->enr.PHP_EOL;
}
echo 'FIRE_VEHICLE='.$fire->id.PHP_EOL.'INCIDENT='.$incident->id.PHP_EOL.'HOSPITAL='.$hospital->id.PHP_EOL.'HOSPITAL_CODE=CEFPROBECODE2345'.PHP_EOL;
