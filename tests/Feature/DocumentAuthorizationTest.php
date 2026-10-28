<?php

use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DocJson as J;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('documents');
    $this->person = Personnel::factory()->create();
    $this->template = DocumentTemplate::factory()->create(['content' => J::doc(J::section([J::para([J::text('Text.')])], 'free', 'free1'))]);
    $this->draft = Document::factory()->create(['personnel_id' => $this->person->id, 'template_id' => $this->template->id, 'content' => $this->template->content]);
    $this->issued = Document::factory()->issued()->create(['personnel_id' => $this->person->id, 'template_id' => $this->template->id, 'public_id' => '7654321', 'pdf_path' => 'editor/7654321.pdf']);
    Storage::disk('documents')->put('editor/7654321.pdf', '%PDF-1.7 test');
});

function authzUser(string ...$perms): User
{
    $role = Role::create(['name' => 'R'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function authzMatrix(?User $user): array
{
    $t = test();
    $d = $t->draft->id;
    $i = $t->issued->id;
    $req = fn (string $method, string $url, array $data = []) => ($user ? $t->actingAs($user) : $t)->json($method, $url, $data)->getStatusCode();

    return [
        'store' => $req('POST', "/personnel/{$t->person->id}/documents", ['template_id' => $t->template->id]),
        'show draft' => $req('GET', "/documents/{$d}"),
        'show issued' => $req('GET', "/documents/{$i}"),
        'edit' => $req('GET', "/documents/{$d}/edit"),
        'pdf issued' => $req('GET', "/documents/{$i}/pdf"),
        'save' => $req('POST', "/documents/{$d}/save", ['content' => json_encode($t->template->content), 'lock_version' => 0, 'autosave' => true]),
        'issue' => $req('POST', "/documents/{$d}/issue"),
        'delete issued' => $req('DELETE', "/documents/{$i}"),
    ];
}

it('rejects guests on every route', function () {
    expect(array_values(array_unique(authzMatrix(null))))->toBe([401]);
});

it('forbids accounts without a document right or own record', function (array $perms) {
    expect(array_values(array_unique(authzMatrix(authzUser(...$perms)))))->toBe([403]);
})->with([[[]], [['personnel.view']], [['personnel.edit']]]);

it('lets the recipient open issued documents of their own record only', function () {
    $recipient = authzUser();
    $recipient->forceFill(['personnel_id' => $this->person->id])->save();

    expect(authzMatrix($recipient))->toBe([
        'store' => 403, 'show draft' => 403, 'show issued' => 200, 'edit' => 403, 'pdf issued' => 200,
        'save' => 403, 'issue' => 403, 'delete issued' => 403,
    ]);

    $other = authzUser();
    $other->forceFill(['personnel_id' => Personnel::factory()->create()->id])->save();
    expect(array_values(array_unique(authzMatrix($other))))->toBe([403]);
});

it('lets viewers open issued documents but never drafts', function () {
    expect(authzMatrix(authzUser('personnel.documents.view')))->toBe([
        'store' => 403, 'show draft' => 403, 'show issued' => 200, 'edit' => 403, 'pdf issued' => 200,
        'save' => 403, 'issue' => 403, 'delete issued' => 403,
    ]);
});

it('lets managers work on drafts but not delete issued documents', function () {
    $m = authzMatrix(authzUser('personnel.documents.manage'));

    expect($m['show draft'])->toBe(302)
        ->and($m['show issued'])->toBe(200)
        ->and($m['edit'])->toBe(200)
        ->and($m['pdf issued'])->toBe(200)
        ->and($m['save'])->toBe(200)
        ->and($m['delete issued'])->toBe(403)
        ->and($m['store'])->toBe(302);
    expect($this->issued->fresh())->not->toBeNull();
});

it('lets managers delete drafts and admins issued documents, removing the pdf', function () {
    $this->actingAs(authzUser('personnel.documents.manage'))->delete("/documents/{$this->draft->id}")
        ->assertRedirect("/personnel/{$this->person->id}?tab=documents");
    expect(Document::find($this->draft->id))->toBeNull();

    $this->actingAs(authzUser('admin'))->delete("/documents/{$this->issued->id}")->assertRedirect();
    expect(Document::find($this->issued->id))->toBeNull();
    Storage::disk('documents')->assertMissing('editor/7654321.pdf');
});

it('never shows a draft pdf, not even to the recipient', function () {
    $recipient = authzUser();
    $recipient->forceFill(['personnel_id' => $this->person->id])->save();

    $this->actingAs($recipient)->get("/documents/{$this->draft->id}/pdf")->assertForbidden();
    $this->actingAs(authzUser('admin'))->get("/documents/{$this->draft->id}/pdf")->assertNotFound();
});

it('keeps the gate names working', function () {
    $viewer = authzUser('personnel.documents.view');
    $manager = authzUser('personnel.documents.manage');
    $admin = authzUser('admin');

    expect($viewer->can('document.view'))->toBeTrue()
        ->and($viewer->can('document.manage'))->toBeFalse()
        ->and($manager->can('document.manage'))->toBeTrue()
        ->and($manager->can('document.viewAudit'))->toBeFalse()
        ->and($admin->can('document.viewAudit'))->toBeTrue()
        ->and($viewer->can('document.viewPdf', $this->issued))->toBeTrue()
        ->and($manager->can('document.delete', $this->draft))->toBeTrue()
        ->and($manager->can('document.delete', $this->issued))->toBeFalse()
        ->and($admin->can('document.delete', $this->issued))->toBeTrue();
});
