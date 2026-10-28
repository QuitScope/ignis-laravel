<?php

use App\Http\Middleware\NormalizeLegacyPath;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Enotf\Models\EnotfProtocol;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

function leuRaw(string $uri): Response
{
    $request = Request::create('/');
    $request->server->set('REQUEST_URI', $uri);

    return (new NormalizeLegacyPath)->handle($request, fn () => new Response('next'));
}

it('redirects the legacy eNOTF urls', function () {
    $p = (new EnotfProtocol)->forceFill(['enr' => '4711']);
    $p->save();
    DB::table('legacy_id_map')->insert([
        ['entity' => 'enotf_protocol', 'legacy_id' => '42', 'new_id' => $p->id],
        ['entity' => 'enotf_protocol', 'legacy_id' => '43', 'new_id' => $p->id + 100],
    ]);
    foreach ([
        ['/enotf/', '/enotf'],
        ['/enotf/index.php', '/enotf'],
        ['/enotf/p/4711/rettdaten/seite', '/enotf/p/4711/rettdaten'],
        ['/enotf/p/4711/abschluss/freigabe?t=x', '/enotf/p/4711/abschluss'],
        ['/enotf/p/4711/unbekannt', '/enotf/p/4711'],
        ['/enotf/p/4711/verlauf', null],
        ['/enotf/p/4711/plausibility', null],
        ['/enotf/protokoll/index.php?enr=4711', '/enotf/p/4711'],
        ['/enotf/protokoll/anamnese/index?enr=4711', '/enotf/p/4711/anamnese'],
        ['/enotf/protokoll?x=1', '/enotf/overview'],
        ['/enotf/print/?enr=4711', '/enotf/print/4711'],
        ['/enotf/admin/', '/enotf/admin'],
        ['/enotf/admin/list?page=2', '/enotf/admin'],
        ['/enotf/admin/qm-actions-modal?id=42', '/enotf/qm/4711'],
        ['/enotf/admin/qm-log-modal?id=43', '/enotf/admin'],
        ['/enotf/qm/actions/42', '/enotf/qm/4711'],
        ['/enotf/qm/log/43', '/enotf/admin'],
        ['/enotf/admin/bulk-delete-empty', '/enotf/admin/empty'],
        ['/enotf-v2/overview?a=1', '/enotf/overview?a=1'],
        ['/settings/enotf/index.php', '/settings/enotf/quicklinks'],
        ['/settings/enotf/kategorien/index', '/settings/enotf/categories'],
        ['/enotf/overview', null],
        ['/enotf/hospital-availability', '/enotf/hospitals'],
        ['/enotf/schnittstelle/hospital-availability.php', '/enotf/schnittstelle/availability'],
        ['/settings/pois/access-codes', '/settings/pois'],
        ['/settings/pois/departments/create', '/settings/pois'],
        ['/settings/pois/7/departments', null],
        ['/enotf/schnittstelle/voranmeldung.php?enr=4711', '/enotf/p/4711/prereg'],
        ['/enotf/schnittstelle/voranmeldung?enr=../x', '/enotf/overview'],
        ['/enotf/schnittstelle/api-prereg', '/enotf/schnittstelle/prereg'],
        ['/enotf/schnittstelle/index.php?klinik=3', '/enotf/schnittstelle'],
        ['/enotf/p/4711/prereg', null],
        ['/enotf/schnittstelle/prereg', null],
    ] as [$from, $to]) {
        $res = leuRaw($from);
        if ($to === null) {
            expect($res->getContent())->toBe('next', $from);
        } else {
            expect($res->getStatusCode())->toBe(301, $from)->and($res->headers->get('Location'))->toBe($to, $from);
        }
    }
});

it('serves the old .php pages without a redirect and never logs out on GET', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/enotf/overview.php')->assertRedirect('/enotf/login');
    $this->post('/enotf/qm/actions/42')->assertNotFound();
    $this->assertAuthenticatedAs($user);
});

it('answers the dropped legacy plugin API with 410 and keeps billing', function () {
    foreach (['/api/klinik/generate-code', '/api/enotf/prereg', '/api/enotf/save-fields', '/api/enotf/share/send-request',
        '/api/generate-klinikcode', '/api/pois/departments-sort', '/api/enotf/prereg.php'] as $uri) {
        $this->postJson($uri)->assertStatus(410);
    }
    $this->getJson('/api/enotf/prereg')->assertStatus(410);
    $this->getJson('/api/enotf/billing')->assertStatus(405);
    $this->postJson('/api/enotf/billing.php')->assertStatus(503);
});
