<?php

use App\Models\LegacyDocument;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('documents');
    $this->person = Personnel::factory()->create(['full_name' => 'Erika Muster']);
    $this->doc = LegacyDocument::factory()->create([
        'personnel_id' => $this->person->id, 'public_id' => 'ABCD-EFGH-JKLM', 'pdf_path' => 'ABCD-EFGH-JKLM.pdf',
        'type' => 1, 'issuer_name' => 'Chef', 'issued_on' => '2024-05-01',
    ]);
    Storage::disk('documents')->put('legacy/ABCD-EFGH-JKLM.pdf', '%PDF-1.4');
});

function ldUser(string ...$perms): User
{
    $role = Role::create(['name' => 'R'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

it('shows the viewer with the pdf to viewers, the recipient and the issuer', function (string $who) {
    $user = match ($who) {
        'viewer' => ldUser('personnel.documents.view'),
        'manager' => ldUser('personnel.documents.manage'),
        'admin' => ldUser('admin'),
        'recipient' => User::factory()->create(['personnel_id' => $this->person->id]),
        'issuer' => tap(User::factory()->create(), fn ($u) => $this->doc->update(['issuer_user_id' => $u->id])),
    };

    $this->actingAs($user)->get('/personnel/document-view?docid=ABCD-EFGH-JKLM')->assertInertia(fn (Assert $p) => $p
        ->component('Documents/LegacyShow')
        ->where('document.public_id', 'ABCD-EFGH-JKLM')
        ->where('document.pdf_url', '/storage/documents/ABCD-EFGH-JKLM.pdf')
        ->where('document.issuer', 'Chef')
        ->where('document.type', 1));
})->with(['viewer', 'manager', 'admin', 'recipient', 'issuer']);

it('refuses strangers, guests and unknown ids', function () {
    $this->get('/personnel/document-view?docid=ABCD-EFGH-JKLM')->assertRedirect('/login');
    $this->actingAs(User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]))
        ->get('/personnel/document-view?docid=ABCD-EFGH-JKLM')->assertForbidden();
    $this->actingAs(ldUser('personnel.documents.view'))->get('/personnel/document-view?docid=NOPE')->assertNotFound();
    $this->actingAs(ldUser('personnel.documents.view'))->get('/personnel/document-view')->assertNotFound();
});

it('shows only the stored data when there is no pdf file', function () {
    Storage::disk('documents')->delete('legacy/ABCD-EFGH-JKLM.pdf');
    $this->doc->update(['recipient_name' => 'Erika Muster']);

    $this->actingAs(ldUser('personnel.documents.view'))->get('/personnel/document-view?docid=ABCD-EFGH-JKLM')
        ->assertInertia(fn (Assert $p) => $p->where('document.pdf_url', null)->where('document.recipient', 'Erika Muster'));
});

it('does not link 7-digit pdfs, which the public route would never serve', function () {
    $this->doc->update(['public_id' => '1234567', 'pdf_path' => '1234567.pdf']);
    Storage::disk('documents')->put('legacy/1234567.pdf', '%PDF');

    $this->actingAs(ldUser('admin'))->get('/personnel/document-view?docid=1234567')->assertInertia(fn (Assert $p) => $p->where('document.pdf_url', null));
});

it('returns the legacy json for get-document', function () {
    $this->actingAs(ldUser('personnel.documents.view'))->getJson('/api/documents/get-document?docid=ABCD-EFGH-JKLM')
        ->assertOk()->assertJson(['success' => true, 'document' => [
            'docid' => 'ABCD-EFGH-JKLM', 'type' => 1, 'ersteller_name' => 'Chef', 'ausstellungsdatum_formatted' => '01.05.2024',
            'is_archived' => false, 'pdf_url' => '/storage/documents/ABCD-EFGH-JKLM.pdf', 'pdf_exists' => true, 'profileid' => $this->person->id,
        ]]);
});

it('checks get-document rights', function () {
    $this->getJson('/api/documents/get-document?docid=ABCD-EFGH-JKLM')->assertUnauthorized();
    $this->actingAs(User::factory()->create(['personnel_id' => $this->person->id]))->getJson('/api/documents/get-document?docid=ABCD-EFGH-JKLM')->assertForbidden();
    $this->actingAs(ldUser('personnel.documents.view'))->getJson('/api/documents/get-document')->assertStatus(400);
    $this->actingAs(ldUser('personnel.documents.view'))->getJson('/api/documents/get-document?docid=NOPE')->assertNotFound();
    $issuer = User::factory()->create();
    $this->doc->update(['issuer_user_id' => $issuer->id]);
    $this->actingAs($issuer)->getJson('/api/documents/get-document?docid=ABCD-EFGH-JKLM')->assertOk();
});

it('archives and restores with audit, only with document.manage', function () {
    $this->actingAs(ldUser('personnel.documents.view'))->postJson('/api/documents/archive', ['docid' => 'ABCD-EFGH-JKLM', 'archived' => true])->assertForbidden();

    $manager = ldUser('personnel.documents.manage');
    $this->actingAs($manager)->postJson('/api/documents/archive', ['docid' => 'ABCD-EFGH-JKLM', 'archived' => true])->assertOk()->assertJson(['success' => true, 'archived' => true]);
    expect($this->doc->refresh()->is_archived)->toBeTrue();
    $this->postJson('/api/documents/archive', ['docid' => 'ABCD-EFGH-JKLM', 'archived' => false])->assertOk();
    expect($this->doc->refresh()->is_archived)->toBeFalse();
    $this->postJson('/api/documents/archive', ['docid' => 'NOPE'])->assertNotFound();
    $this->postJson('/api/documents/archive', [])->assertStatus(400);

    $log = Activity::orderBy('id')->get();
    expect($log->pluck('description')->all())->toBe(['Dokument archiviert [ID: ABCD-EFGH-JKLM]', 'Dokument wiederhergestellt [ID: ABCD-EFGH-JKLM]'])
        ->and($log[0]->log_name)->toBe('Mitarbeiter')->and($log[0]->causer_id)->toBe($manager->id);
});

it('deletes the row and the pdf file, with audit', function () {
    $manager = ldUser('personnel.documents.manage');
    $this->actingAs($manager)->post('/personnel/document-delete', ['docid' => 'ABCD-EFGH-JKLM', 'pid' => (string) $this->person->id])
        ->assertRedirect("/personnel/{$this->person->id}?tab=documents");

    expect(LegacyDocument::count())->toBe(0);
    Storage::disk('documents')->assertMissing('legacy/ABCD-EFGH-JKLM.pdf');
    $entry = Activity::latest('id')->first();
    expect($entry->description)->toBe('Dokument gelöscht [ID: ABCD-EFGH-JKLM]')->and($entry->log_name)->toBe('Mitarbeiter')
        ->and($entry->details)->toBe((string) $this->person->id);
});

it('keeps row and pdf when the delete is refused or the row is unknown', function () {
    $this->post('/personnel/document-delete', ['docid' => 'ABCD-EFGH-JKLM'])->assertRedirect('/login');
    foreach (['personnel.documents.view', 'personnel.view'] as $perm) {
        $this->actingAs(ldUser($perm))->post('/personnel/document-delete', ['docid' => 'ABCD-EFGH-JKLM'])->assertForbidden();
    }
    $this->actingAs(User::factory()->create(['personnel_id' => $this->person->id]))->post('/personnel/document-delete', ['docid' => 'ABCD-EFGH-JKLM'])->assertForbidden();
    $this->actingAs(ldUser('admin'))->post('/personnel/document-delete', ['docid' => 'NOPE'])->assertNotFound();
    $this->actingAs(ldUser('admin'))->post('/personnel/document-delete', [])->assertSessionHasErrors('docid');

    expect(LegacyDocument::count())->toBe(1);
    Storage::disk('documents')->assertExists('legacy/ABCD-EFGH-JKLM.pdf');
});

it('does not follow a hostile pdf_path when deleting', function () {
    Storage::disk('documents')->put('editor/1234567.pdf', 'x');
    $this->doc->update(['pdf_path' => '../editor/1234567.pdf']);

    $this->actingAs(ldUser('admin'))->post('/personnel/document-delete', ['docid' => 'ABCD-EFGH-JKLM'])->assertRedirect();

    Storage::disk('documents')->assertExists('editor/1234567.pdf');
});

it('lists the legacy documents in the profile tab with the archive switch', function () {
    LegacyDocument::factory()->create(['personnel_id' => $this->person->id, 'public_id' => '7654321', 'is_archived' => true]);
    $viewer = ldUser('personnel.documents.view', 'personnel.view');

    $this->actingAs($viewer)->get("/personnel/{$this->person->id}?tab=documents")->assertInertia(fn (Assert $p) => $p
        ->has('legacyDocuments.data', 1)->where('legacyDocuments.data.0.public_id', 'ABCD-EFGH-JKLM')
        ->where('legacyDocuments.data.0.type', 1)->where('showArchived', false)->where('can.delete_legacy_documents', false));
    $this->actingAs($viewer)->get("/personnel/{$this->person->id}?tab=documents&archived=1")->assertInertia(fn (Assert $p) => $p
        ->has('legacyDocuments.data', 1)->where('legacyDocuments.data.0.public_id', '7654321')->where('showArchived', true));
    $this->actingAs(ldUser('personnel.view'))->get("/personnel/{$this->person->id}?tab=documents")->assertInertia(fn (Assert $p) => $p->where('legacyDocuments', null));
    $this->actingAs(ldUser('personnel.documents.manage', 'personnel.view'))->get("/personnel/{$this->person->id}?tab=documents")
        ->assertInertia(fn (Assert $p) => $p->where('can.delete_legacy_documents', true));
});

it('loads the profile legacy list without N+1', function () {
    $viewer = ldUser('personnel.documents.view', 'personnel.view');
    $this->doc->update(['issuer_user_id' => User::factory()->create()->id]);
    $queries = function () use ($viewer) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($viewer)->get("/personnel/{$this->person->id}?tab=documents")->assertOk();
        $n = count(DB::getQueryLog());
        DB::flushQueryLog();

        return $n;
    };
    $queries();
    $before = $queries();
    LegacyDocument::factory()->count(4)->create(['personnel_id' => $this->person->id, 'issuer_user_id' => User::factory()->create()->id]);

    expect($queries())->toBe($before);
});

it('treats array-valued docid like a missing one instead of failing', function () {
    $viewer = ldUser('personnel.documents.view');
    $manager = ldUser('personnel.documents.manage');
    $this->actingAs($viewer)->get('/personnel/document-view?docid[]=x')->assertNotFound();
    $this->actingAs($viewer)->getJson('/api/documents/get-document?docid[]=x')->assertStatus(400);
    $this->actingAs($manager)->postJson('/api/documents/archive', ['docid' => ['x']])->assertStatus(400);
    $this->actingAs($manager)->post('/personnel/document-delete', ['docid' => ['x']])->assertSessionHasErrors('docid');
    expect(LegacyDocument::count())->toBe(1);
});

it('audits the delete with the record of the row, not the posted pid', function () {
    $other = Personnel::factory()->create();
    $this->actingAs(ldUser('personnel.documents.manage'))->post('/personnel/document-delete', ['docid' => 'ABCD-EFGH-JKLM', 'pid' => (string) $other->id])
        ->assertRedirect("/personnel/{$this->person->id}?tab=documents");

    expect(Activity::latest('id')->first()->details)->toBe((string) $this->person->id);
});

it('sends a readable type label, also for types without a translation', function () {
    $this->doc->update(['type' => 4]);

    $this->actingAs(ldUser('personnel.documents.view'))->get('/personnel/document-view?docid=ABCD-EFGH-JKLM')
        ->assertInertia(fn (Assert $p) => $p->where('document.type_label', __('documents.legacy_type.unknown')));
    $this->actingAs(User::factory()->create(['personnel_id' => $this->person->id]))->get('/me/documents')
        ->assertInertia(fn (Assert $p) => $p->where('documents.data.0.type_label', __('documents.legacy_type.unknown')));
});
