<?php

use App\Models\Document;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('documents');
    Storage::disk('documents')->put('editor/1234567.pdf', '%PDF-1.7 test');
    $this->doc = Document::factory()->issued()->create(['public_id' => '1234567', 'pdf_path' => 'editor/1234567.pdf']);
});

function pdfUser(string ...$perms): User
{
    $role = Role::create(['name' => 'R'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

it('redirects guests to the login', function () {
    $this->get("/documents/{$this->doc->id}/pdf")->assertRedirect('/login');
});

it('forbids users without the document right', function () {
    $this->actingAs(pdfUser('personnel.view'))->get("/documents/{$this->doc->id}/pdf")->assertForbidden();
});

it('serves the pdf inline with private, non-sniffable headers', function () {
    $res = $this->actingAs(pdfUser('personnel.documents.view'))->get("/documents/{$this->doc->id}/pdf");

    $res->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'inline');
    expect($res->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=300')->not->toContain('public');
    expect($res->streamedContent())->toBe('%PDF-1.7 test');
});

it('answers 404 for an unknown or malformed id', function () {
    $u = pdfUser('admin');
    $this->actingAs($u)->get('/documents/999999/pdf')->assertNotFound();
    $this->actingAs($u)->get('/documents/abc/pdf')->assertNotFound();
    $this->actingAs($u)->get('/documents/99999999999999999999/pdf')->assertNotFound();
});

it('answers 404 without pdf_path or when the file is gone', function () {
    $u = pdfUser('admin');
    $this->doc->update(['pdf_path' => null]);
    $this->actingAs($u)->get("/documents/{$this->doc->id}/pdf")->assertNotFound();

    $this->doc->update(['pdf_path' => 'editor/1234567.pdf']);
    Storage::disk('documents')->delete('editor/1234567.pdf');
    $this->actingAs($u)->get("/documents/{$this->doc->id}/pdf")->assertNotFound();
});

it('never serves a path outside editor/ even if pdf_path is tampered', function () {
    Storage::disk('documents')->put('legacy/secret.pdf', '%PDF-secret');
    $this->doc->update(['pdf_path' => '../documents/legacy/secret.pdf']);

    $this->actingAs(pdfUser('admin'))->get("/documents/{$this->doc->id}/pdf")->assertNotFound();
});

it('does not serve the pdf of a draft', function () {
    $this->doc->update(['status' => Document::DRAFT]);

    $this->actingAs(pdfUser('admin'))->get("/documents/{$this->doc->id}/pdf")->assertNotFound();
});
