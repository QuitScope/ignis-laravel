<?php

use App\Http\LegacyUrlMap;
use App\Http\Middleware\NormalizeLegacyPath;
use App\Import\LegacyLinkRewriter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\FireTab\Models\FireIncident;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

function lfuRaw(string $uri): Response
{
    $request = Request::create('/');
    $request->server->set('REQUEST_URI', $uri);

    return (new NormalizeLegacyPath)->handle($request, fn () => new Response('next'));
}

function lfuSeed(): int
{
    $incident = FireIncident::query()->forceCreate(['number' => 'F-1', 'location' => 'Ort', 'keyword' => 'B1']);
    DB::table('legacy_id_map')->insert([
        ['entity' => 'fire_incident', 'legacy_id' => '42', 'new_id' => $incident->id],
        ['entity' => 'fire_incident', 'legacy_id' => '43', 'new_id' => $incident->id + 100],
    ]);

    return $incident->id;
}

it('redirects the legacy fireTab pages', function () {
    $i = lfuSeed();

    foreach ([
        '/firetab/view?id=42' => "/firetab/incidents/{$i}",
        '/firetab/view.php?id=42&tab=bericht' => "/firetab/incidents/{$i}?tab=bericht",
        '/firetab/view?id=42&tab=<x>' => "/firetab/incidents/{$i}",
        '/einsatz/view.php?id=42&tab=atemschutz' => "/firetab/incidents/{$i}?tab=atemschutz",
        '/firetab/asu?incident_id=42' => "/firetab/incidents/{$i}?tab=atemschutz",
        '/einsatz/asu.php?incident_id=42' => "/firetab/incidents/{$i}?tab=atemschutz",
        '/firetab/view?id=77' => '/firetab/list',
        '/firetab/view?id=43' => '/firetab/list',
        '/firetab/view?id=//evil.example' => '/firetab/list',
        '/firetab/view?id[]=42' => '/firetab/list',
        '/firetab/asu?incident_id=77' => '/firetab/list',
        '/firetab/create' => '/firetab/incidents/create',
        '/einsatz/create.php' => '/firetab/incidents/create',
        '/firetab/login-vehicle?logout=1' => '/firetab/login',
        '/firetab/status-reports' => '/firetab/status',
        '/firetab/admin/list?show_archived=1&q=Brand&sort=nr&dir=asc&page=2' => '/firetab/qm?archived=1&q=Brand&sort=number&dir=asc',
        '/firetab/admin/list?sort=leader' => '/firetab/qm',
        '/firetab/index' => '/firetab',
    ] as $from => $to) {
        $this->get($from)->assertStatus(301)->assertRedirect($to);
    }
    $r = lfuRaw('/firetab/?x=1');
    expect($r->getStatusCode())->toBe(301)->and($r->headers->get('Location'))->toBe('/firetab');
});

it('keeps the live pages and the tablet entry (no redirect)', function () {
    expect(lfuRaw('/einsatz/list.php')->getContent())->toBe('next')->and(lfuRaw('/firetab/list')->getContent())->toBe('next')
        ->and(lfuRaw('/firetab/logbook')->getContent())->toBe('next');
    foreach (['/firetab', '/firetab/list', '/firetab/login', '/firetab/status', '/firetab/qm', '/firetab/logbook', '/firetab/incidents/create'] as $path) {
        expect(LegacyUrlMap::redirectFor($path))->toBeNull();
    }
});

it('has no legacy POST endpoints and no fire API any more', function () {
    lfuSeed();
    foreach ([
        ['POST', '/firetab/view?id=42'],
        ['POST', '/firetab/create'],
        ['POST', '/firetab/admin/list'],
        ['POST', '/firetab/lagekarte-api'],
        ['GET', '/firetab/status-api'],
        ['GET', '/api/fire/incidents'],
        ['POST', '/api/v1/fire/status'],
    ] as [$method, $uri]) {
        $status = $this->actingAs(User::factory()->create(['is_super_admin' => true]))->json($method, $uri, ['id' => 42])->status();
        expect($status)->toBeIn([404, 405]);
    }
});

it('rewrites imported fireTab links', function () {
    $links = new LegacyLinkRewriter([], [], fireIncidents: ['42' => 420]);

    foreach ([
        '/firetab/view?id=42' => ['/firetab/incidents/420', 'rewritten'],
        '/einsatz/view.php?id=42&tab=bericht#x' => ['/firetab/incidents/420?tab=bericht', 'rewritten'],
        '/firetab/asu?incident_id=42' => ['/firetab/incidents/420?tab=atemschutz', 'rewritten'],
        '/firetab/view?id=43' => ['/firetab/list', 'unmapped'],
        '/firetab/view?id[]=42' => ['/firetab/list', 'unmapped'],
    ] as $raw => $expected) {
        expect($links->notificationLink($raw))->toBe($expected);
    }
    expect($links->tileTarget('firetab/view?id=42'))->toBe(['/firetab/incidents/420', 'rewritten', true]);
});
