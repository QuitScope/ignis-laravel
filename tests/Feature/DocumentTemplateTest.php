<?php

use App\Http\Requests\SaveDocumentRequest;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\DocJson as J;

uses(RefreshDatabase::class);

const TPL = '/settings/documents/editor-templates';

beforeEach(fn () => $this->seed(RoleSeeder::class));

function tplUser(string ...$perms): User
{
    $role = Role::create(['name' => 'T'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function tplManager(): User
{
    return tplUser('personnel.documents.manage');
}

function tplPayload(array $over = []): array
{
    $doc = J::doc(J::section([J::para([J::text('Fest')])], 'locked', 'a'), J::section([J::para()], 'free', 'b'));

    return array_replace(['name' => 'Verwarnung', 'category' => 'Personal', 'content' => json_encode($doc)], $over);
}

it('gates every route: guests 401, no right 403, manage/admin/super admin pass', function () {
    $t = DocumentTemplate::factory()->create();
    $routes = [
        ['GET', TPL], ['GET', TPL.'/create'], ['POST', TPL.'/create'], ['GET', TPL.'/'.$t->id],
        ['PUT', TPL.'/'.$t->id], ['DELETE', TPL.'/'.$t->id], ['POST', TPL.'/'.$t->id.'/deactivate'], ['POST', TPL.'/'.$t->id.'/reactivate'],
    ];
    foreach ($routes as [$m, $u]) {
        expect($this->json($m, $u, tplPayload())->getStatusCode())->toBe(401, "$m $u guest");
    }
    foreach ($routes as [$m, $u]) {
        foreach ([tplUser(), tplUser('personnel.documents.view'), tplUser('personnel.view')] as $nobody) {
            expect($this->actingAs($nobody)->json($m, $u, tplPayload())->getStatusCode())->toBe(403, "$m $u no right");
        }
    }
    foreach ([tplManager(), tplUser('admin'), User::factory()->superAdmin()->create()] as $ok) {
        $this->actingAs($ok)->get(TPL)->assertOk();
        $this->actingAs($ok)->get(TPL.'/create')->assertOk();
        $this->actingAs($ok)->get(TPL.'/'.$t->id)->assertOk();
    }
});

it('lists templates with category, status and document count in a fixed number of queries', function () {
    $a = DocumentTemplate::factory()->create(['name' => 'A', 'category' => 'X']);
    DocumentTemplate::factory()->create(['name' => 'B', 'is_active' => false]);
    Document::factory()->count(3)->create(['template_id' => $a->id]);
    $user = tplManager();

    $this->actingAs($user)->get(TPL);
    $counter = 0;
    DB::listen(function () use (&$counter) {
        $counter++;
    });
    $this->actingAs($user)->get(TPL)->assertInertia(fn (Assert $p) => $p
        ->component('Settings/Documents/Templates')
        ->has('templates', 2)
        ->where('templates.0', fn ($t) => $t['name'] === 'A' && $t['category'] === 'X' && $t['is_active'] === true && $t['documents'] === 3)
        ->where('templates.1', fn ($t) => $t['name'] === 'B' && $t['is_active'] === false && $t['documents'] === 0));
    $few = $counter;

    DocumentTemplate::factory()->count(5)->create();
    $counter = 0;
    $this->actingAs($user)->get(TPL);
    expect($counter)->toBe($few);
});

it('offers a new template with exactly one free section', function () {
    $this->actingAs(tplManager())->get(TPL.'/create')->assertInertia(fn (Assert $p) => $p
        ->component('Settings/Documents/TemplateEdit')
        ->where('template', null)
        ->where('content', fn ($c) => count($c['content']) === 1
            && $c['content'][0]['type'] === 'docSection'
            && $c['content'][0]['attrs']['mode'] === 'free'
            && $c['content'][0]['attrs']['sectionId'] !== ''));
});

it('creates a template and writes the audit entry', function () {
    $user = tplManager();
    $this->actingAs($user)->post(TPL.'/create', tplPayload())->assertRedirect(TPL);

    $t = DocumentTemplate::firstOrFail();
    expect($t->name)->toBe('Verwarnung')->and($t->category)->toBe('Personal')->and($t->is_active)->toBeTrue()
        ->and($t->created_by)->toBe($user->id)->and($t->content['content'])->toHaveCount(2);
    $log = Activity::where('log_name', 'Dokumente')->sole();
    expect($log->description)->toBe('Dokumentvorlage erstellt')->and($log->details)->toBe("Name: Verwarnung [ID: {$t->id}]")
        ->and((bool) $log->is_global)->toBeTrue();
});

it('stores an empty category as null', function () {
    $this->actingAs(tplManager())->post(TPL.'/create', tplPayload(['category' => '']))->assertRedirect();
    expect(DocumentTemplate::sole()->category)->toBeNull();
});

it('updates name, category and content with an audit entry', function () {
    $t = DocumentTemplate::factory()->create(['name' => 'Alt']);
    $new = J::doc(J::section([J::para([J::text('Neu')])], 'locked', 'z'));
    $this->actingAs(tplManager())->put(TPL.'/'.$t->id, tplPayload(['name' => 'Neu', 'content' => json_encode($new)]))->assertRedirect(TPL);

    expect($t->fresh()->name)->toBe('Neu')->and($t->fresh()->content['content'][0]['attrs']['sectionId'])->toBe('z');
    $log = Activity::where('log_name', 'Dokumente')->sole();
    expect($log->description)->toBe('Dokumentvorlage aktualisiert')->and($log->details)->toBe("Name: Neu [ID: {$t->id}]");
});

it('rejects invalid input with 422', function (array $over, string $field) {
    $this->actingAs(tplManager())->postJson(TPL.'/create', tplPayload($over))->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(DocumentTemplate::count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'name too long' => [['name' => str_repeat('a', 151)], 'name'],
    'category too long' => [['category' => str_repeat('a', 101)], 'category'],
    'no content' => [['content' => null], 'content'],
    'not json' => [['content' => 'nope{'], 'content'],
    'json scalar' => [['content' => '"x"'], 'content'],
]);

it('shows the validator reasons for bad template content', function (Closure $doc, string $reason) {
    $r = $this->actingAs(tplManager())->postJson(TPL.'/create', tplPayload(['content' => json_encode($doc())]));
    $r->assertUnprocessable()->assertJsonValidationErrors('content');
    expect($r->json('errors.content.0'))->toContain($reason);
    expect(DocumentTemplate::count())->toBe(0);
})->with([
    'paragraph on top' => [fn () => J::doc(J::para([J::text('x')])), 'nur Abschnitte'],
    'duplicate section id' => [fn () => J::doc(J::section([J::para()], 'free', 'a'), J::section([J::para()], 'free', 'a')), 'a'],
    'duplicate field id' => [fn () => J::doc(J::section([J::para([J::field('f'), J::field('f')])], 'free', 'a')), 'f'],
    'empty doc' => [fn () => J::doc(), 'nur Abschnitte'],
    'unknown node' => [fn () => J::doc(J::section([['type' => 'script']], 'free', 'a')), 'script'],
]);

it('rejects content over 1 MB with 422 and an oversized body with 413, nothing stored', function () {
    $user = tplManager();
    $over = json_encode(J::doc(J::section([J::para([J::text(str_repeat('a', 1024 * 1024 + 1))])], 'free', 'a')));
    $this->actingAs($user)->postJson(TPL.'/create', tplPayload(['content' => $over]))
        ->assertUnprocessable()->assertJsonValidationErrors('content');

    $this->actingAs($user)->postJson(TPL.'/create', tplPayload(['content' => str_repeat('a', SaveDocumentRequest::MAX_BODY)]))->assertStatus(413);
    expect(DocumentTemplate::count())->toBe(0);
});

it('deactivates and reactivates a template, with audit entries', function () {
    $t = DocumentTemplate::factory()->create(['name' => 'V']);
    $user = tplManager();
    $this->actingAs($user)->post(TPL.'/'.$t->id.'/deactivate')->assertRedirect();
    expect($t->fresh()->is_active)->toBeFalse();
    $this->actingAs($user)->post(TPL.'/'.$t->id.'/reactivate')->assertRedirect();
    expect($t->fresh()->is_active)->toBeTrue();

    $logs = Activity::where('log_name', 'Dokumente')->orderBy('id')->get();
    expect($logs->pluck('description')->all())->toBe(['Dokumentvorlage deaktiviert', 'Dokumentvorlage aktiviert'])
        ->and($logs[0]->details)->toBe("Name: V [ID: {$t->id}]");
});

it('deletes an unused template but refuses (422) one with documents', function () {
    $user = tplManager();
    $free = DocumentTemplate::factory()->create(['name' => 'Frei']);
    $used = DocumentTemplate::factory()->create(['name' => 'Benutzt']);
    $doc = Document::factory()->create(['template_id' => $used->id]);

    $this->actingAs($user)->deleteJson(TPL.'/'.$used->id)->assertUnprocessable()->assertJsonValidationErrors('delete');
    expect($used->fresh())->not->toBeNull()->and($doc->fresh()->template_id)->toBe($used->id)
        ->and(Activity::where('log_name', 'Dokumente')->count())->toBe(0);

    $this->actingAs($user)->delete(TPL.'/'.$free->id)->assertRedirect(TPL);
    expect(DocumentTemplate::find($free->id))->toBeNull();
    $log = Activity::where('log_name', 'Dokumente')->sole();
    expect($log->description)->toBe('Dokumentvorlage gelöscht')->and($log->details)->toBe("Name: Frei [ID: {$free->id}]");
});

it('reports open drafts of the template on the edit page', function () {
    $t = DocumentTemplate::factory()->create();
    Document::factory()->count(2)->create(['template_id' => $t->id]);
    Document::factory()->issued()->create(['template_id' => $t->id]);

    $this->actingAs(tplManager())->get(TPL.'/'.$t->id)->assertInertia(fn (Assert $p) => $p
        ->component('Settings/Documents/TemplateEdit')
        ->where('openDrafts', 2)->where('template.id', $t->id)->where('content', $t->content));
});

it('answers 404 for unknown or non-numeric ids', function () {
    $u = tplManager();
    $this->actingAs($u)->get(TPL.'/999')->assertNotFound();
    $this->actingAs($u)->get(TPL.'/abc')->assertNotFound();
    $this->actingAs($u)->delete(TPL.'/999')->assertNotFound();
});

it('redirects the old template pages permanently', function (string $from) {
    $this->get($from)->assertStatus(301)->assertRedirect(TPL);
})->with(['/settings/documents/templates', '/settings/documents/categories', '/settings/documents/visual-editor']);

it('refuses a template with an oversized table with a readable UTF-8 message', function () {
    $table = ['type' => 'table', 'content' => array_fill(0, 201, ['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [J::para()]]]])];
    $doc = J::doc(J::section([$table], 'free', 'a'));

    $res = $this->actingAs(tplManager())->postJson(TPL.'/create', tplPayload(['content' => json_encode($doc)]))->assertStatus(422);

    expect(json_encode($res->json()))->not->toBeFalse()
        ->and(json_encode($res->json('errors.content'), JSON_UNESCAPED_UNICODE))->toContain('höchstens 200 Zeilen');
    expect(DocumentTemplate::count())->toBe(0);
});
