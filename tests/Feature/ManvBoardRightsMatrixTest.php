<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvPatient;
use Modules\ManvBoard\Models\ManvResource;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

const P7A_ROUTES = [
    'GET mci', 'GET mci/create', 'GET mci/personnel', 'POST mci',
    'GET mci/{incident}/edit', 'PUT mci/{incident}', 'GET mci/{incident}/log', 'GET mci/{incident}',
    'GET mci/{incident}/patients/create', 'POST mci/{incident}/patients', 'GET mci/{incident}/patients/{patient}',
    'PUT mci/{incident}/patients/{patient}', 'PATCH mci/{incident}/patients/{patient}/triage', 'POST mci/{incident}/patients/{patient}/departure',
    'GET mci/{incident}/resources', 'POST mci/{incident}/resources', 'PUT mci/{incident}/resources/{resource}', 'DELETE mci/{incident}/resources/{resource}',
];

function p7a_role_user(string ...$perms): User
{
    $role = Role::create(['name' => 'X'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function p7a_actor(string $actor): ?User
{
    return match ($actor) {
        'guest' => null,
        'plain' => p7a_role_user('audit.view'),
        'view' => p7a_role_user('mci.view'),
        'manage' => p7a_role_user('mci.manage'),
        'admin' => p7a_role_user('admin'),
        'super-admin' => User::factory()->superAdmin()->create(),
    };
}

function p7a_checker(?User $user, array &$bad, array &$seen): Closure
{
    return function (string $template, string $url, int|string $expected, array $data = []) use ($user, &$bad, &$seen) {
        [$method] = explode(' ', $template, 2);
        $seen[$template] = true;
        if (! in_array($template, P7A_ROUTES, true)) {
            $bad[] = "{$template}: not in P7A_ROUTES";
        }
        app('auth')->forgetGuards();
        $request = $user === null ? test() : test()->actingAs($user);
        $response = $request->call($method, $url, $data);
        $got = $response->isRedirect(url('/login')) ? 'login' : $response->getStatusCode();
        if ($got !== $expected) {
            $bad[] = "{$template}: {$method} {$url} expected {$expected}, got {$got}";
        }
        $errors = $expected === 302 && $got === 302 ? $response->getSession()?->get('errors') : null;
        if ($errors !== null && $errors->any()) {
            $bad[] = "{$template}: {$method} {$url} redirected with errors: ".json_encode($errors->getBags()['default']->toArray());
        }
    };
}

function p7a_incident(string $status = 'active'): ManvIncident
{
    $i = (new ManvIncident)->forceFill(['incident_number' => 'L-'.uniqid(), 'location' => 'Halle', 'started_at' => now(), 'status' => $status]);
    $i->save();

    return $i;
}

function p7a_resource(ManvIncident $i, ?int $vehicle = null): ManvResource
{
    $r = (new ManvResource)->forceFill(['manv_incident_id' => $i->id, 'type' => 'vehicle', 'label' => 'RTW '.uniqid(), 'vehicle_id' => $vehicle]);
    $r->save();

    return $r;
}

function p7a_patient(ManvIncident $i, bool $ready = false): ManvPatient
{
    $resource = $ready ? p7a_resource($i, Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS])->id) : null;
    $p = (new ManvPatient)->forceFill(['manv_incident_id' => $i->id, 'number' => 'MANV-'.uniqid(), 'triage' => 'SK1',
        'manv_resource_id' => $resource?->id, 'destination' => $ready ? 'Klinikum Nord' : null]);
    $p->save();

    return $p;
}

it('lists exactly the ManvBoard routes the matrix covers', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => $r->uri() === 'mci' || str_starts_with($r->uri(), 'mci/'))
        ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->map(fn ($m) => "{$m} {$r->uri()}"))
        ->sort()->values()->all();

    $expected = P7A_ROUTES;
    sort($expected);
    expect($routes)->toBe($expected);
});

it('answers every ManvBoard route on an active Lage with the exact status for every account', function (string $actor) {
    $user = p7a_actor($actor);
    $in = fn (string ...$who) => in_array($actor, $who, true);
    $guest = $actor === 'guest';
    $deny = $guest ? 'login' : 403;
    $reads = $in('view', 'manage', 'admin', 'super-admin');
    $writes = $in('manage', 'admin', 'super-admin');

    $i = p7a_incident();
    $p = p7a_patient($i);
    $ready = p7a_patient($i, true);
    $res = p7a_resource($i);
    $gone = p7a_resource($i);
    $before = [ManvIncident::count(), ManvPatient::count(), ManvResource::count()];
    $gonePk = $gone->id;

    $bad = [];
    $seen = [];
    $check = p7a_checker($user, $bad, $seen);
    $r = fn (int|string $ok, bool $allowed) => $allowed ? $ok : $deny;

    $check('GET mci', '/mci', $r(200, $reads));
    $check('GET mci/create', '/mci/create', $r(200, $writes));
    $check('GET mci/personnel', '/mci/personnel?q=a', $r(200, $writes));
    $check('GET mci/{incident}/edit', "/mci/{$i->id}/edit", $r(200, $writes));
    $check('GET mci/{incident}/log', "/mci/{$i->id}/log", $r(200, $reads));
    $check('GET mci/{incident}', "/mci/{$i->id}", $r(200, $reads));
    $check('GET mci/{incident}/patients/create', "/mci/{$i->id}/patients/create", $r(200, $writes));
    $check('GET mci/{incident}/patients/{patient}', "/mci/{$i->id}/patients/{$p->id}", $r(200, $reads));
    $check('GET mci/{incident}/resources', "/mci/{$i->id}/resources", $r(200, $reads));

    $check('POST mci', '/mci', $r(302, $writes), ['incident_number' => 'N-'.uniqid(), 'location' => 'Ort']);
    $check('PUT mci/{incident}', "/mci/{$i->id}", $r(302, $writes), ['incident_number' => 'U-'.uniqid(), 'location' => 'Ort', 'status' => 'active']);
    $check('POST mci/{incident}/patients', "/mci/{$i->id}/patients", $r(302, $writes), ['triage' => 'SK2', 'sex' => 'm']);
    $check('PUT mci/{incident}/patients/{patient}', "/mci/{$i->id}/patients/{$p->id}", $r(302, $writes), ['triage' => 'SK2', 'sex' => 'f']);
    $check('PATCH mci/{incident}/patients/{patient}/triage', "/mci/{$i->id}/patients/{$p->id}/triage", $r(302, $writes), ['triage' => 'SK3']);
    $check('POST mci/{incident}/patients/{patient}/departure', "/mci/{$i->id}/patients/{$ready->id}/departure", $r(302, $writes));
    $check('POST mci/{incident}/resources', "/mci/{$i->id}/resources", $r(302, $writes), ['type' => 'vehicle', 'label' => 'Neu '.uniqid()]);
    $check('PUT mci/{incident}/resources/{resource}', "/mci/{$i->id}/resources/{$res->id}", $r(302, $writes), ['label' => $res->label, 'notes' => 'x']);
    $check('DELETE mci/{incident}/resources/{resource}', "/mci/{$i->id}/resources/{$gone->id}", $r(302, $writes));

    expect($bad)->toBe([])->and(array_keys($seen))->toEqualCanonicalizing(P7A_ROUTES);
    if ($writes) {
        expect(ManvIncident::count())->toBe($before[0] + 1)
            ->and($i->refresh()->incident_number)->toStartWith('U-')
            ->and(ManvPatient::count())->toBe($before[1] + 1)
            ->and($p->refresh()->triage->value)->toBe('SK3')
            ->and($p->sex->value)->toBe('f')
            ->and($ready->refresh()->departed_at)->not->toBeNull()
            ->and(ManvResource::whereKey($gonePk)->exists())->toBeFalse()
            ->and(ManvResource::count())->toBe($before[2] + 1 - 1)
            ->and($res->refresh()->notes)->toBe('x');
    }
    if (! $writes) {
        expect([ManvIncident::count(), ManvPatient::count(), ManvResource::count()])->toBe($before)
            ->and($ready->refresh()->departed_at)->toBeNull()->and($p->refresh()->triage->value)->toBe('SK1');
    }
})->with(['guest', 'plain', 'view', 'manage', 'admin', 'super-admin']);

it('keeps a closed or archived Lage read-only except for the status', function (string $status, string $actor) {
    $user = p7a_actor($actor);
    $in = fn (string ...$who) => in_array($actor, $who, true);
    $guest = $actor === 'guest';
    $deny = $guest ? 'login' : 403;
    $reads = $in('view', 'manage', 'admin', 'super-admin');
    $writes = $in('manage', 'admin', 'super-admin');

    $i = p7a_incident($status);
    $p = p7a_patient($i);
    $ready = p7a_patient($i, true);
    $res = p7a_resource($i);
    $r = fn (int|string $ok, bool $allowed) => $allowed ? $ok : $deny;

    $bad = [];
    $seen = [];
    $check = p7a_checker($user, $bad, $seen);

    $check('GET mci/{incident}', "/mci/{$i->id}", $r(200, $reads));
    $check('GET mci/{incident}/log', "/mci/{$i->id}/log", $r(200, $reads));
    $check('GET mci/{incident}/patients/{patient}', "/mci/{$i->id}/patients/{$p->id}", $r(200, $reads));
    $check('GET mci/{incident}/resources', "/mci/{$i->id}/resources", $r(200, $reads));
    $check('GET mci/{incident}/edit', "/mci/{$i->id}/edit", $r(200, $writes));
    $check('GET mci/{incident}/patients/create', "/mci/{$i->id}/patients/create", $deny);
    $check('POST mci/{incident}/patients', "/mci/{$i->id}/patients", $deny, ['triage' => 'SK2', 'sex' => 'm']);
    $check('PUT mci/{incident}/patients/{patient}', "/mci/{$i->id}/patients/{$p->id}", $deny, ['triage' => 'SK2', 'sex' => 'f']);
    $check('PATCH mci/{incident}/patients/{patient}/triage', "/mci/{$i->id}/patients/{$p->id}/triage", $deny, ['triage' => 'SK3']);
    $check('POST mci/{incident}/patients/{patient}/departure', "/mci/{$i->id}/patients/{$ready->id}/departure", $deny);
    $check('POST mci/{incident}/resources', "/mci/{$i->id}/resources", $deny, ['type' => 'vehicle', 'label' => 'Neu']);
    $check('PUT mci/{incident}/resources/{resource}', "/mci/{$i->id}/resources/{$res->id}", $deny, ['label' => $res->label]);
    $check('DELETE mci/{incident}/resources/{resource}', "/mci/{$i->id}/resources/{$res->id}", $deny);
    $check('PUT mci/{incident}', "/mci/{$i->id}", $r(302, $writes), ['status' => 'active']);

    expect($bad)->toBe([]);
    expect($i->refresh()->status->value)->toBe($writes ? 'active' : $status);
    expect($ready->refresh()->departed_at)->toBeNull()->and($p->refresh()->triage->value)->toBe('SK1')->and(ManvResource::whereKey($res->id)->exists())->toBeTrue();
})->with(function () {
    foreach (['guest', 'plain', 'view', 'manage', 'admin', 'super-admin'] as $actor) {
        yield "closed / {$actor}" => ['closed', $actor];
    }
    // archived takes the same read-only branch; one denied, one reader and one writer row suffice
    foreach (['plain', 'view', 'manage'] as $actor) {
        yield "archived / {$actor}" => ['archived', $actor];
    }
});

it('answers a patient or resource of another Lage and an unknown Lage with 404 for every logged-in account', function (string $actor) {
    $user = p7a_actor($actor);
    $guest = $actor === 'guest';
    $not = $guest ? 'login' : 404;
    $i = p7a_incident();
    $other = p7a_incident();
    $foreign = p7a_patient($other, true);
    $foreignRes = p7a_resource($other);

    $bad = [];
    $seen = [];
    $check = p7a_checker($user, $bad, $seen);
    $check('GET mci/{incident}/patients/{patient}', "/mci/{$i->id}/patients/{$foreign->id}", $not);
    $check('PUT mci/{incident}/patients/{patient}', "/mci/{$i->id}/patients/{$foreign->id}", $not, ['triage' => 'SK2', 'sex' => 'f']);
    $check('PATCH mci/{incident}/patients/{patient}/triage', "/mci/{$i->id}/patients/{$foreign->id}/triage", $not, ['triage' => 'SK3']);
    $check('POST mci/{incident}/patients/{patient}/departure', "/mci/{$i->id}/patients/{$foreign->id}/departure", $not);
    $check('PUT mci/{incident}/resources/{resource}', "/mci/{$i->id}/resources/{$foreignRes->id}", $not, ['label' => 'x']);
    $check('DELETE mci/{incident}/resources/{resource}', "/mci/{$i->id}/resources/{$foreignRes->id}", $not);
    $check('GET mci/{incident}', '/mci/999999', $not);

    expect($bad)->toBe([])->and($foreign->refresh()->departed_at)->toBeNull()->and(ManvResource::whereKey($foreignRes->id)->exists())->toBeTrue();
})->with(['guest', 'plain', 'view', 'manage', 'admin', 'super-admin']);

it('never changes state on GET: the write URLs answer 405', function () {
    $i = p7a_incident();
    $p = p7a_patient($i, true);
    $user = p7a_actor('manage');

    foreach (["/mci/{$i->id}/patients/{$p->id}/triage?triage=SK2", "/mci/{$i->id}/patients/{$p->id}/departure"] as $url) {
        $this->actingAs($user)->get($url)->assertStatus(405);
    }
    $this->actingAs($user)->get("/mci/{$i->id}/patients/{$p->id}?quick_sk=SK2")->assertOk();
    $this->actingAs($user)->get("/mci/{$i->id}/resources?delete_id=1")->assertOk();
    expect($p->refresh()->triage->value)->toBe('SK1')->and($p->departed_at)->toBeNull();
});
