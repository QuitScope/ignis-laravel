<?php

use App\Http\LegacyUrlMap;
use App\Http\Middleware\NormalizeLegacyPath;
use App\Import\LegacyLinkRewriter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvPatient;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

function lmuRaw(string $uri): Response
{
    $request = Request::create('/');
    $request->server->set('REQUEST_URI', $uri);

    return (new NormalizeLegacyPath)->handle($request, fn () => new Response('next'));
}

function lmuSeed(): array
{
    $incident = ManvIncident::query()->forceCreate(['incident_number' => 'E-1', 'location' => 'Ort', 'started_at' => now()]);
    $patient = ManvPatient::query()->forceCreate(['manv_incident_id' => $incident->id, 'number' => 'MANV-001']);
    DB::table('legacy_id_map')->insert([
        ['entity' => 'manv_incident', 'legacy_id' => '42', 'new_id' => $incident->id],
        ['entity' => 'manv_incident', 'legacy_id' => '43', 'new_id' => $incident->id + 100],
        ['entity' => 'manv_patient', 'legacy_id' => '7', 'new_id' => $patient->id],
        ['entity' => 'manv_patient', 'legacy_id' => '8', 'new_id' => $patient->id + 100],
    ]);

    return [$incident->id, $patient->id];
}

it('redirects the legacy MANV pages through the id map', function () {
    [$i, $p] = lmuSeed();

    foreach ([
        '/mci/board?id=42' => "/mci/{$i}",
        '/mci/board.php?id=42&x=1' => "/mci/{$i}",
        '/mci/edit?id=42' => "/mci/{$i}/edit",
        '/mci/log?id=42' => "/mci/{$i}/log",
        '/mci/patient-view?id=7' => "/mci/{$i}/patients/{$p}",
        '/mci/patient-view?id=7&quick_sk=SK1' => "/mci/{$i}/patients/{$p}",
        '/mci/patient-create?lage_id=42' => "/mci/{$i}/patients/create",
        '/mci/resources?lage_id=42' => "/mci/{$i}/resources",
        '/mci/resources?lage_id=42&delete_id=3' => "/mci/{$i}/resources",
        '/manv/board?id=42' => "/mci/{$i}",
        '/manv/patient-view.php?id=7' => "/mci/{$i}/patients/{$p}",
        '/manv/resources?lage_id=42' => "/mci/{$i}/resources",
    ] as $from => $to) {
        $this->get($from)->assertStatus(301)->assertRedirect($to);
    }
});

it('leads unknown, deleted or bad ids to the list', function () {
    lmuSeed();
    foreach ([
        '/mci/board?id=77', '/mci/board?id=43', '/mci/board', '/mci/board?id=abc', '/mci/board?id[]=42', '/mci/board?id=0',
        '/mci/board?id=//evil.example', '/mci/edit?id=77', '/mci/log', '/mci/patient-view?id=8', '/mci/patient-view?id=42',
        '/mci/patient-create?id=42', '/mci/resources?lage_id=77', '/manv/edit?id=99',
    ] as $from) {
        $this->get($from)->assertStatus(301)->assertRedirect('/mci');
    }
});

it('redirects the list pages and keeps the query with the legacy status translated', function () {
    $this->get('/mci/index')->assertStatus(301)->assertRedirect('/mci');
    $this->get('/mci/index.php?status=archiviert')->assertStatus(301)->assertRedirect('/mci?status=archived');
    $this->get('/manv/index?status=abgeschlossen')->assertStatus(301)->assertRedirect('/mci?status=closed');
    $this->get('/manv?status=aktiv')->assertStatus(301)->assertRedirect('/mci?status=active');
    $this->get('/manv/create')->assertStatus(301)->assertRedirect('/mci/create');

    foreach (['/mci/', '/manv/'] as $from) {
        $r = lmuRaw($from.'?status=aktiv');
        expect($r->getStatusCode())->toBe(301)->and($r->headers->get('Location'))->toBe('/mci?status=active');
    }
});

it('keeps the new pages live (no redirect, no loop)', function () {
    [$i, $p] = lmuSeed();
    $admin = User::factory()->create(['is_super_admin' => true]);
    foreach (['/mci', '/mci?status=closed', '/mci/create', "/mci/{$i}", "/mci/{$i}/edit", "/mci/{$i}/log", "/mci/{$i}/resources", "/mci/{$i}/patients/create", "/mci/{$i}/patients/{$p}"] as $uri) {
        $this->actingAs($admin)->get($uri)->assertOk();
    }
    expect(LegacyUrlMap::redirectFor('/mci/create'))->toBeNull()->and(LegacyUrlMap::redirectFor('/mci'))->toBeNull();
});

it('has no legacy POST endpoints and no MANV API any more', function () {
    lmuSeed();
    foreach ([
        ['POST', '/mci/edit?id=42'],
        ['POST', '/mci/patient-view?id=7'],
        ['POST', '/mci/resources?lage_id=42'],
        ['POST', '/mci/patient-create?lage_id=42'],
        ['GET', '/api/mci/api?action=get_stats'],
        ['POST', '/api/mci/api?action=transport_abfahrt'],
        ['GET', '/api/mci-api?action=get_patients'],
        ['GET', '/api/manv/api?action=get_stats'],
        ['GET', '/api/v1/mci/api?action=get_lage'],
    ] as [$method, $uri]) {
        $status = $this->actingAs(User::factory()->create(['is_super_admin' => true]))->json($method, $uri, ['id' => 1])->status();
        expect($status)->toBeIn([404, 405]);
    }
});

it('rewrites imported MANV links', function () {
    $links = new LegacyLinkRewriter([], [], [], [], [], ['42' => 420], ['7' => [420, 70]]);

    foreach ([
        '/mci/board?id=42' => ['/mci/420', 'rewritten'],
        '/manv/board.php?id=42#x' => ['/mci/420', 'rewritten'],
        '/mci/edit?id=42' => ['/mci/420/edit', 'rewritten'],
        '/mci/log?id=42' => ['/mci/420/log', 'rewritten'],
        '/mci/patient-view?id=7' => ['/mci/420/patients/70', 'rewritten'],
        '/manv/patient-create?lage_id=42' => ['/mci/420/patients/create', 'rewritten'],
        '/mci/resources?lage_id=42' => ['/mci/420/resources', 'rewritten'],
        '/mci/board?id=43' => ['/mci', 'unmapped'],
        '/mci/patient-view?id=8' => ['/mci', 'unmapped'],
        '/mci/patient-view?id[]=7' => ['/mci', 'unmapped'],
        '/mci/' => ['/mci', 'rewritten'],
        '/manv/index?status=archiviert' => ['/mci?status=archived', 'rewritten'],
        '/mci/create' => ['/mci/create', 'rewritten'],
    ] as $raw => $expected) {
        expect($links->notificationLink($raw))->toBe($expected);
    }
    expect($links->tileTarget('manv/board?id=42'))->toBe(['/mci/420', 'rewritten', true]);
});
