<?php

// Wegwerf-Setup fuer den Screenshot-Vergleich (nach tests/e2e/cef/setup.php). Nur gegen eine SQLite-Datei mit "visual" im Namen:
//   DB_DATABASE=<tmp>/visual.sqlite php tests/e2e/visual/setup.php <baseUrl> fresh [system|dark|light]|data|token [admin|viewer] [<to>]
// fresh: migrate:fresh --seed, Module an, organisation.type/city, Nutzer visual.admin (Super-Admin) und visual.viewer
//        (Rolle "Visual Viewer" mit Personalakte, KEIN Super-Admin: schreibt als Besatzung, auch fuer Tablet-Seiten).
//        Darstellung beider Nutzer Standard `system`: shot.mjs --schemes dark,light schaltet dann per prefers-color-scheme.
// data:  Fahrzeuge, Einsaetze (FireTab), eNOTF-Protokolle; gibt VEHICLE (RD), FIRE_VEHICLE, ENR, INCIDENT, PERSONNEL aus.
// token: gibt die Tablet-Anmelde-URL des Nutzers aus (Standard admin); <to>=enotf haengt &to=enotf an.
use App\Install\InstallState;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\TabletLoginToken;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings;
use Illuminate\Contracts\Console\Kernel;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\FireTab\Models\FireIncident;

$repo = dirname(__DIR__, 3);
require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_contains((string) config('database.connections.sqlite.database'), 'visual') || config('database.default') !== 'sqlite') {
    fwrite(STDERR, "Refusing: DB_DATABASE must contain 'visual'.\n");
    exit(1);
}

$mode = $argv[2] ?? 'fresh';
if ($mode === 'fresh') {
    Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
    $settings = app(Settings::class);
    $settings->set(InstallState::KEY, now()->toIso8601String());
    $settings->set('auth.tablet_login', true);
    $settings->set('system.name', 'ıgnıs');
    foreach (['firetab', 'enotf', 'forms', 'logbook', 'calendar', 'knowledgebase', 'mail', 'manvboard', 'federation'] as $module) {
        $settings->set("modules.$module.enabled", true);
    }
    $settings->set('organisation.type', 'Berufsfeuerwehr');
    $settings->set('organisation.city', 'Musterstadt');
    $theme = $argv[3] ?? 'system';
    User::factory()->create(['is_super_admin' => true, 'name' => 'Max Mustermann', 'username' => 'visual.admin', 'theme' => $theme]);
    $role = Role::create(['name' => 'Visual Viewer', 'guard_name' => 'web', 'priority' => 500, 'color' => 'secondary', 'is_default' => false]);
    $role->syncPermissions(['personnel.view', 'vehicles.view', 'edivi.view', 'fire.incident.qm', 'application.view', 'mail.use', 'calendar.view', 'mci.view', 'logbook.view']);
    User::factory()->withRole($role)->create(['name' => 'Erika Muster', 'username' => 'visual.viewer', 'theme' => $theme, 'personnel_id' => Personnel::factory()->create()->id]);
    exit(0);
}
if ($mode === 'data') {
    $v = [];
    $F = Vehicle::SERVICE_FIRE;
    $E = Vehicle::SERVICE_EMS;
    foreach ([['HLF 20/1', 'HLF', $F, '2', 50], ['DLK 23/1', 'DLK', $F, '1', 40], ['RTW 83/1', 'RTW', $E, '3', 30], ['NEF 82/1', 'NEF', $E, '4', 20], ['KTW 85/1', 'KTW', $E, '6', 10]] as $i => [$name, $type, $service, $fms, $ago]) {
        $v[] = Vehicle::factory()->create(['name' => $name, 'type' => $type, 'service' => $service, 'fms_status' => $fms, 'fms_status_at' => now()->subMinutes($ago), 'sort_order' => $i + 1]);
    }
    foreach ([['Hauptstr. 1', 'B3', 3], ['Bahnhofstr. 12', 'TH1', 2], ['Marktplatz 5', 'B2', 1]] as [$loc, $kw, $h]) {
        (new FireIncident)->forceFill(['location' => $loc, 'keyword' => $kw, 'started_at' => now()->subHours($h)])->save();
    }
    (new EnotfProtocol)->forceFill(['enr' => '260001', 'vehicle_transport_id' => $v[2]->id])->save();
    (new EnotfProtocol)->forceFill(['enr' => '260002', 'vehicle_transport_id' => $v[2]->id])->save();
}
if ($mode === 'data') {
    $inc = FireIncident::orderBy('id')->first();
    echo 'VEHICLE='.$v[2]->id.PHP_EOL.'FIRE_VEHICLE='.$v[0]->id.PHP_EOL.'ENR=260001'.PHP_EOL.'INCIDENT='.$inc->id.PHP_EOL.'PERSONNEL='.Personnel::orderBy('id')->value('id').PHP_EOL;
    exit(0);
}
$user = User::where('username', 'visual.'.($argv[3] ?? 'admin'))->firstOrFail();
echo rtrim($argv[1] ?? 'http://127.0.0.1:8422', '/').'/auth/tablet?token='.TabletLoginToken::issue($user).(isset($argv[4]) ? '&to='.$argv[4] : '').PHP_EOL;
