<?php

use App\Http\LegacyUrlMap;
use App\Http\Middleware\NormalizeLegacyPath;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

function lcuEvent(): int
{
    return (int) DB::table('calendar_events')->insertGetId(['title' => 'T', 'starts_at' => '2026-11-10 10:00:00', 'ends_at' => '2026-11-10 11:00:00',
        'visibility' => 'all', 'created_at' => now(), 'updated_at' => now()]);
}

function lcuRaw(string $uri): Response
{
    $request = Request::create('/');
    $request->server->set('REQUEST_URI', $uri);

    return (new NormalizeLegacyPath)->handle($request, fn () => new Response('next'));
}

it('redirects the old calendar pages', function () {
    foreach ([
        ['/calendar/view', '/calendar'],
        ['/calendar/view.php?id=7', '/calendar'],
        ['/calendar/view?id=abc', '/calendar'],
        ['/calendar/view?id[]=1', '/calendar'],
        ['/calendar/view?id=0', '/calendar'],
        ['/calendar/view?id=//evil.example', '/calendar'],
        ['/kalender/view?id=7', '/calendar'],
        ['/kalender/create', '/calendar/create'],
    ] as [$from, $to]) {
        $this->get($from)->assertStatus(301)->assertRedirect($to);
    }
});

it('redirects the trailing-slash pages', function () {
    foreach (['/calendar/', '/kalender/'] as $from) {
        $r = lcuRaw($from);
        expect($r->getStatusCode())->toBe(301)->and($r->headers->get('Location'))->toBe('/calendar');
    }
});

it('leads an imported legacy event id to the event', function () {
    $id = lcuEvent();
    DB::table('legacy_id_map')->insert(['entity' => 'calendar_event', 'legacy_id' => '42', 'new_id' => $id]);
    DB::table('legacy_id_map')->insert(['entity' => 'calendar_event', 'legacy_id' => '43', 'new_id' => $id + 100]);

    $this->get('/calendar/view?id=42')->assertStatus(301)->assertRedirect("/calendar?event={$id}");
    $this->get('/calendar/view.php?id=42&x=1')->assertStatus(301)->assertRedirect("/calendar?event={$id}");
    $this->get('/calendar/view?id=43')->assertStatus(301)->assertRedirect('/calendar');
});

it('keeps /calendar/create as the live page with the create dialog (no redirect, no loop)', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    expect(LegacyUrlMap::redirectFor('/calendar/create'))->toBeNull();
    $this->actingAs($admin)->get('/calendar/create')->assertOk()->assertInertia(fn ($p) => $p->component('Calendar/Index')->where('openNew', true));
    $this->actingAs($admin)->get('/calendar/create.php')->assertOk();
    $this->actingAs($admin)->get('/calendar?new=1')->assertOk()->assertInertia(fn ($p) => $p->where('openNew', true));
    $this->actingAs($admin)->get('/calendar')->assertOk()->assertInertia(fn ($p) => $p->where('openNew', false));
});

it('has no legacy POST endpoints and no legacy calendar API any more', function () {
    foreach ([
        ['POST', '/calendar/create', 405],
        ['POST', '/calendar/update?id=1', 404],
        ['POST', '/calendar/delete?id=1', 404],
        ['POST', '/calendar/respond?id=1', 404],
        ['GET', '/api/calendar/events?start=2026-01-01&end=2026-02-01', 404],
        ['GET', '/api/v1/calendar/events', 404],
        ['GET', '/api/kalender/events', 404],
        ['GET', '/api/calendar/event?id=1', 404],
        ['GET', '/api/calendar/subscribe-info', 404],
        ['POST', '/api/calendar/subscribe-regenerate', 404],
    ] as [$method, $uri, $status]) {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]))
            ->json($method, $uri, ['id' => 1, 'title' => 'x'])->assertStatus($status);
    }
});

it('keeps the new feed apart from the dropped legacy feed path', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($admin)->getJson('/calendar/events?from=2026-11-01&to=2026-12-01')->assertOk();
});
