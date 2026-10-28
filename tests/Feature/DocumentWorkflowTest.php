<?php

use App\Documents\DocumentValidator;
use App\Events\DocumentIssued;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\LegacyDocument;
use App\Models\Personnel;
use App\Models\PersonnelLogEntry;
use App\Models\Role;
use App\Models\User;
use App\Personnel\PersonnelLogger;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\DocJson as J;

uses(RefreshDatabase::class);

const WF_LOCKED = '11111111-1111-4111-8111-111111111111';
const WF_FREE = '22222222-2222-4222-8222-222222222222';

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('documents');
    $this->person = Personnel::factory()->create(['full_name' => 'Erika Muster', 'gender' => 1]);
    $this->template = DocumentTemplate::factory()->create(['name' => 'Urkunde', 'content' => wfTemplate()]);
    $this->manager = wfUser('personnel.documents.manage');
});

function wfUser(string ...$perms): User
{
    $role = Role::create(['name' => 'R'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function wfTemplate(string $fieldValue = '', string $variable = 'mitarbeiter.name'): array
{
    return J::doc(
        J::section([J::para([J::text('Aus der Vorlage. '), J::field('grund', array_filter(['label' => 'Grund', 'required' => true, 'value' => $fieldValue]))])], 'locked', WF_LOCKED),
        J::section([J::para([J::text('Für '), J::variable($variable)])], 'free', WF_FREE),
    );
}

function wfDraft(array $content): Document
{
    return Document::factory()->create([
        'personnel_id' => test()->person->id, 'template_id' => test()->template->id, 'title' => 'Urkunde', 'content' => $content,
    ]);
}

function wfSave(Document $doc, array $content, int $lock = 0, array $over = [])
{
    return test()->actingAs(test()->manager)->postJson("/documents/{$doc->id}/save", $over + [
        'content' => json_encode($content), 'title' => 'Urkunde', 'lock_version' => $lock,
    ]);
}

function wfAudits(string $action): int
{
    return Activity::where('log_name', 'Dokumente')->where('description', 'like', $action.'%')->count();
}

it('creates a draft with the template content', function () {
    $res = $this->actingAs($this->manager)->post("/personnel/{$this->person->id}/documents", ['template_id' => $this->template->id]);

    $doc = Document::sole();
    $res->assertRedirect("/documents/{$doc->id}/edit");
    expect($doc->status)->toBe(Document::DRAFT)
        ->and($doc->title)->toBe('Urkunde')
        ->and($doc->content)->toEqual(wfTemplate())
        ->and($doc->public_id)->toMatch('/^\d{7}$/')
        ->and($doc->created_by)->toBe($this->manager->id)
        ->and($doc->lock_version)->toBe(0);
    $audit = Activity::where('log_name', 'Dokumente')->sole();
    expect($audit->description)->toBe('Dokument angelegt')
        ->and($audit->details)->toBe("Kennung: {$doc->public_id}, Titel: Urkunde")
        ->and((bool) $audit->is_global)->toBeTrue();
});

it('does not create from an inactive or unknown template, and ignores injected columns', function () {
    $this->template->update(['is_active' => false]);
    $this->actingAs($this->manager)->post("/personnel/{$this->person->id}/documents", ['template_id' => $this->template->id])->assertSessionHasErrors('template_id');
    $this->actingAs($this->manager)->post("/personnel/{$this->person->id}/documents", ['template_id' => 99999])->assertSessionHasErrors('template_id');
    expect(Document::count())->toBe(0);

    $this->template->update(['is_active' => true]);
    $this->actingAs($this->manager)->post("/personnel/{$this->person->id}/documents", [
        'template_id' => $this->template->id, 'title' => 'Eigener Titel', 'status' => 'issued', 'lock_version' => 9, 'pdf_path' => 'x',
    ]);
    expect(Document::sole())->status->toBe(Document::DRAFT)->title->toBe('Eigener Titel')->lock_version->toBe(0)->pdf_path->toBeNull();
});

it('restores a tampered locked section from the current template and reports it', function () {
    $doc = wfDraft(wfTemplate());
    $current = wfTemplate();
    $current['content'][0]['content'][0]['content'][0]['text'] = 'Neue Fassung. ';
    $this->template->update(['content' => $current]);

    $tampered = wfTemplate('Verdienste');
    $tampered['content'][0]['content'][0]['content'][0]['text'] = 'Heimlich geändert.';
    $tampered['content'][1]['content'][0] = J::para([J::text('Frei <script>alert(1)</script> geschrieben.')]);

    $res = wfSave($doc, $tampered)->assertOk();

    expect($res->json('lock_version'))->toBe(1)
        ->and($res->json('warnings'))->toContain('Abschnitt 1: gesperrter Inhalt wurde aus der Vorlage wiederhergestellt.');
    $saved = $doc->fresh();
    expect($saved->content['content'][0]['content'][0]['content'][0]['text'])->toBe('Neue Fassung. ')
        ->and($saved->content['content'][0]['content'][0]['content'][1]['attrs']['value'])->toBe('Verdienste')
        ->and($saved->content['content'][1]['content'][0]['content'][0]['text'])->toBe('Frei <script>alert(1)</script> geschrieben.')
        ->and($saved->lock_version)->toBe(1);
});

it('answers 409 to a save with a stale lock_version and keeps the newer content', function () {
    $doc = wfDraft(wfTemplate());
    wfSave($doc, wfTemplate('Erster'))->assertOk()->assertJson(['lock_version' => 1]);

    wfSave($doc, wfTemplate('Zweiter'), 0)->assertStatus(409)->assertJson(['reason' => 'stale']);

    expect($doc->fresh()->content['content'][0]['content'][0]['content'][1]['attrs']['value'])->toBe('Erster')
        ->and($doc->fresh()->lock_version)->toBe(1);
    wfSave($doc, wfTemplate('Erster'), 1)->assertOk()->assertJson(['lock_version' => 2]);
});

it('rejects invalid, oversized and too deep content with 422', function () {
    $doc = wfDraft(wfTemplate());
    $bad = wfTemplate();
    $bad['content'][1]['content'][] = ['type' => 'script', 'content' => []];
    wfSave($doc, $bad)->assertStatus(422);

    $this->actingAs($this->manager)->call('POST', "/documents/{$doc->id}/save", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        json_encode(['content' => str_repeat('ä', DocumentValidator::MAX_BYTES / 2 + 1), 'lock_version' => 0], JSON_UNESCAPED_UNICODE),
    )->assertStatus(422)->assertJsonValidationErrors('content');

    $deep = '{"type":"doc","content":['.str_repeat('{"type":"blockquote","content":[', 300).str_repeat(']}', 300).']}';
    $this->actingAs($this->manager)->postJson("/documents/{$doc->id}/save", ['content' => $deep, 'lock_version' => 0])->assertStatus(422);

    $this->actingAs($this->manager)->postJson("/documents/{$doc->id}/save", ['content' => 'nicht json', 'lock_version' => 0])->assertStatus(422);
    $this->actingAs($this->manager)->postJson("/documents/{$doc->id}/save", ['content' => json_encode(wfTemplate())])->assertJsonValidationErrors('lock_version');
    expect($doc->fresh()->lock_version)->toBe(0);
});

it('refuses a body over the limit', function () {
    $doc = wfDraft(wfTemplate());
    $this->actingAs($this->manager)->call('POST', "/documents/{$doc->id}/save", [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], str_repeat('x', 2 * DocumentValidator::MAX_BYTES + 70 * 1024))->assertStatus(413);
});

it('audits an explicit save but not an autosave', function () {
    $doc = wfDraft(wfTemplate());

    wfSave($doc, wfTemplate(), 0, ['autosave' => true])->assertOk();
    expect(wfAudits('Dokument gespeichert'))->toBe(0);

    wfSave($doc, wfTemplate(), 1)->assertOk();
    $audit = Activity::where('description', 'Dokument gespeichert')->sole();
    expect($audit->log_name)->toBe('Dokumente')->and($audit->details)->toBe("Kennung: {$doc->public_id}, Titel: Urkunde");
});

it('keeps drafts without an active template read-only', function () {
    $doc = wfDraft(wfTemplate());
    $this->template->update(['is_active' => false]);
    wfSave($doc, wfTemplate('x'))->assertStatus(409)->assertJson(['reason' => 'read_only']);

    $this->actingAs($this->manager)->get("/documents/{$doc->id}/edit")
        ->assertInertia(fn (Assert $p) => $p->component('Documents/Edit')->where('readOnly', true));

    $this->template->delete();
    wfSave($doc, wfTemplate('x'))->assertStatus(409)->assertJson(['reason' => 'read_only']);
    expect($doc->fresh()->lock_version)->toBe(0);
});

it('passes the editor what it needs', function () {
    $doc = wfDraft(wfTemplate());

    $this->actingAs($this->manager)->get("/documents/{$doc->id}/edit")->assertInertia(fn (Assert $p) => $p
        ->component('Documents/Edit')
        ->where('document.id', $doc->id)
        ->where('document.lock_version', 0)
        ->where('document.content', wfTemplate())
        ->where('document.gender', 1)
        ->where('template.name', 'Urkunde')
        ->where('readOnly', false)
        ->where('values', fn ($v) => $v['mitarbeiter.name'] === 'Erika Muster')
        ->has('variables', fn (Assert $v) => $v->where('0.key', 'mitarbeiter.name')->etc())
        ->has('missingReasons')
        ->where('urls.save', "/documents/{$doc->id}/save")
        ->where('urls.issue', "/documents/{$doc->id}/issue"));
});

it('issues once: freezes values, writes the pdf, event, audit and history', function () {
    Event::fake([DocumentIssued::class]);
    $doc = wfDraft(wfTemplate('Verdienste'));

    $this->actingAs($this->manager)->post("/documents/{$doc->id}/issue")->assertRedirect("/documents/{$doc->id}");

    $doc->refresh();
    expect($doc->status)->toBe(Document::ISSUED)
        ->and($doc->issued_by)->toBe($this->manager->id)
        ->and($doc->issued_at)->not->toBeNull()
        ->and($doc->frozen_values['mitarbeiter.name'])->toBe('Erika Muster')
        ->and($doc->pdf_path)->toBe("editor/{$doc->public_id}.pdf");
    expect(array_filter($doc->frozen_values, fn ($v) => ! is_string($v)))->toBe([]);
    expect(Storage::disk('documents')->get($doc->pdf_path))->toStartWith('%PDF');
    Event::assertDispatchedTimes(DocumentIssued::class, 1);
    Event::assertDispatched(DocumentIssued::class, fn (DocumentIssued $e) => $e->document->is($doc));
    expect(Activity::where('description', 'Dokument ausgestellt')->sole()->details)->toBe("Kennung: {$doc->public_id}, Titel: Urkunde");
    $log = PersonnelLogEntry::where('type', PersonnelLogEntry::DOCUMENT)->sole();
    expect($log->personnel_id)->toBe($this->person->id)
        ->and($log->content)->toBe("Dokument ausgestellt: Urkunde (Kennung {$doc->public_id}).")
        ->and($log->content)->not->toContain('<');

    $this->actingAs($this->manager)->post("/documents/{$doc->id}/issue")->assertRedirect("/documents/{$doc->id}");
    $this->actingAs($this->manager)->postJson("/documents/{$doc->id}/issue")->assertStatus(409);
    Event::assertDispatchedTimes(DocumentIssued::class, 1);
    expect(wfAudits('Dokument ausgestellt'))->toBe(1);

    wfSave($doc, wfTemplate('Anders'), $doc->lock_version)->assertStatus(409)->assertJson(['reason' => 'issued']);
    $this->actingAs($this->manager)->get("/documents/{$doc->id}/edit")->assertRedirect("/documents/{$doc->id}");
});

it('lets only one of two parallel issue requests win', function () {
    Event::fake([DocumentIssued::class]);
    $doc = wfDraft(wfTemplate('Verdienste'));
    $raced = false;
    Event::listen(TransactionBeginning::class, function () use ($doc, &$raced) {
        if (! $raced) {
            $raced = true;
            DB::table('documents')->where('id', $doc->id)->update(['status' => Document::ISSUED, 'issued_at' => now()]);
        }
    });

    $this->actingAs($this->manager)->postJson("/documents/{$doc->id}/issue")->assertStatus(409);

    expect($raced)->toBeTrue();
    Event::assertNotDispatched(DocumentIssued::class);
    expect(wfAudits('Dokument ausgestellt'))->toBe(0)
        ->and(PersonnelLogEntry::where('type', PersonnelLogEntry::DOCUMENT)->count())->toBe(0)
        ->and($doc->fresh()->pdf_path)->toBeNull();
    Storage::disk('documents')->assertMissing("editor/{$doc->public_id}.pdf");
});

it('does not issue with an empty required field, even with accept_missing', function () {
    $doc = wfDraft(wfTemplate());

    $this->actingAs($this->manager)->post("/documents/{$doc->id}/issue", ['accept_missing' => true])->assertSessionHasErrors('fields');
    $this->actingAs($this->manager)->postJson("/documents/{$doc->id}/issue")->assertStatus(422)->assertJsonValidationErrors('fields');

    expect($doc->fresh()->status)->toBe(Document::DRAFT);
    Storage::disk('documents')->assertMissing("editor/{$doc->public_id}.pdf");
});

it('issues with unresolved variables only after accept_missing and says so in the audit', function () {
    $doc = wfDraft(wfTemplate('Verdienste', 'organisation.name'));

    $this->actingAs($this->manager)->postJson("/documents/{$doc->id}/issue")->assertStatus(422)->assertJsonValidationErrors('variables');
    expect($doc->fresh()->status)->toBe(Document::DRAFT);

    $this->actingAs($this->manager)->post("/documents/{$doc->id}/issue", ['accept_missing' => '1'])->assertRedirect("/documents/{$doc->id}");
    expect($doc->fresh()->status)->toBe(Document::ISSUED)
        ->and(Activity::where('description', 'Dokument ausgestellt')->sole()->details)
        ->toBe("Kennung: {$doc->public_id}, Titel: Urkunde, leer gelassen: Organisation: Name");
});

it('stays a draft when the pdf cannot be written', function () {
    Event::fake([DocumentIssued::class]);
    $doc = wfDraft(wfTemplate('Verdienste'));
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->andReturnFalse();
    $disk->shouldReceive('delete')->andReturnTrue();
    Storage::shouldReceive('disk')->with('documents')->andReturn($disk);

    $this->actingAs($this->manager)->postJson("/documents/{$doc->id}/issue")->assertStatus(422)->assertJsonValidationErrors('issue');

    $doc->refresh();
    expect($doc->status)->toBe(Document::DRAFT)->and($doc->frozen_values)->toBeNull()->and($doc->issued_by)->toBeNull();
    Event::assertNotDispatched(DocumentIssued::class);
    expect(wfAudits('Dokument ausgestellt'))->toBe(0)->and(PersonnelLogEntry::where('type', PersonnelLogEntry::DOCUMENT)->count())->toBe(0);
});

it('shows an issued document with its pdf and sends managers from a draft to the editor', function () {
    $doc = wfDraft(wfTemplate());
    $this->actingAs($this->manager)->get("/documents/{$doc->id}")->assertRedirect("/documents/{$doc->id}/edit");

    $doc->forceFill(['status' => Document::ISSUED, 'issued_by' => $this->manager->id, 'issued_at' => now(), 'pdf_path' => "editor/{$doc->public_id}.pdf"])->save();
    $this->actingAs($this->manager)->get("/documents/{$doc->id}")->assertInertia(fn (Assert $p) => $p
        ->component('Documents/Show')
        ->where('document.public_id', $doc->public_id)
        ->where('document.pdf_url', "/documents/{$doc->id}/pdf")
        ->where('document.personnel.id', $this->person->id)
        ->where('can.delete', false));
});

it('blocks deleting a record with documents and allows it once they are gone', function () {
    $admin = wfUser('admin');
    $doc = wfDraft(wfTemplate());

    $this->actingAs($admin)->delete("/personnel/{$this->person->id}")->assertSessionHasErrors('delete');
    expect(Personnel::find($this->person->id))->not->toBeNull()->and($doc->fresh())->not->toBeNull();

    $this->actingAs($admin)->delete("/documents/{$doc->id}")->assertRedirect();
    $legacy = LegacyDocument::factory()->create(['personnel_id' => $this->person->id]);
    $this->actingAs($admin)->delete("/personnel/{$this->person->id}")->assertSessionHasErrors('delete');
    expect($legacy->fresh())->not->toBeNull();

    $legacy->delete();
    $this->actingAs($admin)->delete("/personnel/{$this->person->id}")->assertRedirect('/personnel');
    expect(Personnel::find($this->person->id))->toBeNull();
    expect(Activity::where('log_name', 'Dokumente')->where('description', 'Dokument gelöscht')->sole()->details)->toBe("Kennung: {$doc->public_id}, Titel: Urkunde");
});

it('answers 422 when a document slips in between the check and the delete', function () {
    Personnel::deleting(function (Personnel $p) {
        Document::factory()->create(['personnel_id' => $p->id, 'template_id' => test()->template->id]);
    });

    $this->actingAs(wfUser('admin'))->delete("/personnel/{$this->person->id}")->assertSessionHasErrors('delete');
    expect(Personnel::find($this->person->id))->not->toBeNull();
});

it('lists the documents in the record, drafts only for managers, templates to create from', function () {
    $draft = wfDraft(wfTemplate());
    $issued = Document::factory()->issued()->create(['personnel_id' => $this->person->id, 'template_id' => $this->template->id, 'title' => 'Fertig']);
    DocumentTemplate::factory()->create(['name' => 'Alt', 'is_active' => false]);

    $this->actingAs($this->manager)->get("/personnel/{$this->person->id}?tab=documents")->assertForbidden();

    $manager = wfUser('personnel.view', 'personnel.documents.manage');
    $this->actingAs($manager)->get("/personnel/{$this->person->id}?tab=documents")->assertInertia(fn (Assert $p) => $p
        ->where('tab', 'documents')
        ->has('documents.data', 2)
        ->where('templates', [['id' => $this->template->id, 'name' => 'Urkunde', 'category' => null]])
        ->where('can.manage_documents', true));

    $viewer = wfUser('personnel.view', 'personnel.documents.view');
    $this->actingAs($viewer)->get("/personnel/{$this->person->id}")->assertInertia(fn (Assert $p) => $p
        ->has('documents.data', 1)
        ->where('documents.data.0.id', $issued->id)
        ->where('templates', [])
        ->where('can.manage_documents', false));

    $this->actingAs(wfUser('personnel.view'))->get("/personnel/{$this->person->id}")->assertInertia(fn (Assert $p) => $p->where('documents', null));
    expect($draft->exists)->toBeTrue();
});

it('answers 422 with a readable message for a table over the row limit (valid UTF-8, not a 500)', function () {
    $doc = wfDraft(wfTemplate());
    $table = ['type' => 'table', 'content' => array_fill(0, 201, ['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [J::para()]]]])];
    $content = J::doc(wfTemplate()['content'][0], J::section([$table], 'free', WF_FREE));

    $res = wfSave($doc, $content)->assertStatus(422);

    expect(json_encode($res->json()))->not->toBeFalse()
        ->and(json_encode($res->json('errors.content'), JSON_UNESCAPED_UNICODE))->toContain('höchstens 200 Zeilen');
    expect(Document::find($doc->id)->lock_version)->toBe(0);
});

it('keeps the title "0" instead of the template name', function () {
    $this->actingAs($this->manager)->post("/personnel/{$this->person->id}/documents", ['template_id' => $this->template->id, 'title' => '0']);

    expect(Document::sole()->title)->toBe('0');
});

it('reports a database lock error while issuing as "try again" and removes the unneeded pdf', function () {
    $doc = wfDraft(wfTemplate('Verdienste'));
    $this->mock(PersonnelLogger::class)->shouldReceive('documentIssued')
        ->andThrow(new QueryException('sqlite', 'update documents', [], new Exception('lock wait timeout')));

    $res = $this->actingAs($this->manager)->postJson("/documents/{$doc->id}/issue")->assertStatus(422)->assertJsonValidationErrors('issue');

    expect($res->json('errors.issue.0'))->toBe(__('documents.error.try_again'))->not->toBe(__('documents.error.pdf_failed'));
    expect($doc->fresh()->status)->toBe(Document::DRAFT);
    Storage::disk('documents')->assertMissing("editor/{$doc->public_id}.pdf");
});

it('keeps the pdf file when the row was issued by the winner of a race meanwhile', function () {
    $doc = wfDraft(wfTemplate('Verdienste'));
    $path = "editor/{$doc->public_id}.pdf";
    $this->mock(PersonnelLogger::class)->shouldReceive('documentIssued')->andThrow(new RuntimeException('boom'));
    Event::listen(TransactionRolledBack::class, function () use ($doc, $path) {
        DB::table('documents')->where('id', $doc->id)->update(['status' => Document::ISSUED]);
        Storage::disk('documents')->put($path, 'WINNER');
    });

    $this->actingAs($this->manager)->postJson("/documents/{$doc->id}/issue")->assertStatus(422);

    expect(Storage::disk('documents')->get($path))->toBe('WINNER');
});
