<?php

use App\Documents\SystemTemplates;
use App\Documents\VariableCatalog;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DocumentTemplatesSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $role = Role::create(['name' => 'Editor', 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['personnel.documents.manage']);
    $this->writer = User::factory()->withRole($role)->create();
});

function editorCatalog(): array
{
    return array_map(fn (string $key, string $label) => ['key' => $key, 'label' => $label], array_keys(VariableCatalog::catalog()), VariableCatalog::catalog());
}

it('gives the document editor its whole contract', function () {
    $person = Personnel::factory()->create(['gender' => 0]);
    $template = DocumentTemplate::factory()->create();
    $doc = Document::factory()->create(['personnel_id' => $person->id, 'template_id' => $template->id, 'lock_version' => 4]);

    $this->actingAs($this->writer)->get("/documents/{$doc->id}/edit")->assertInertia(fn (Assert $p) => $p
        ->component('Documents/Edit')
        ->where('document', [
            'id' => $doc->id, 'public_id' => $doc->public_id, 'title' => $doc->title, 'content' => $doc->content,
            'lock_version' => 4, 'gender' => 0, 'personnel' => ['id' => $person->id, 'name' => $person->displayName()],
        ])
        ->where('template', ['id' => $template->id, 'name' => $template->name, 'is_active' => true])
        ->where('variables', editorCatalog())
        ->where('values', VariableCatalog::resolve($doc->fresh()))
        ->where('missingReasons', VariableCatalog::missingReasons($doc->fresh()))
        ->where('readOnly', false)
        ->where('urls', [
            'save' => "/documents/{$doc->id}/save", 'issue' => "/documents/{$doc->id}/issue",
            'delete' => "/documents/{$doc->id}", 'personnel' => "/personnel/{$person->id}?tab=documents",
        ])
        ->where('can', ['delete' => true]));
});

it('gives the template editor content and the variable catalogue', function () {
    $template = DocumentTemplate::factory()->create();

    $this->actingAs($this->writer)->get('/settings/documents/editor-templates/'.$template->id)->assertInertia(fn (Assert $p) => $p
        ->component('Settings/Documents/TemplateEdit')
        ->where('content', $template->content)
        ->where('variables', editorCatalog())
        ->has('openDrafts'));
});

it('hands every system template to both editors exactly as seeded', function () {
    $this->seed(DocumentTemplatesSeeder::class);
    $person = Personnel::factory()->create();

    foreach (SystemTemplates::all() as $name => $system) {
        $template = DocumentTemplate::where('name', $name)->sole();
        $this->actingAs($this->writer)->get('/settings/documents/editor-templates/'.$template->id)
            ->assertInertia(fn (Assert $p) => $p->where('content', $system['content']));

        $this->actingAs($this->writer)->post("/personnel/{$person->id}/documents", ['template_id' => $template->id]);
        $doc = Document::where('template_id', $template->id)->sole();
        $this->actingAs($this->writer)->get("/documents/{$doc->id}/edit")
            ->assertInertia(fn (Assert $p) => $p->where('document.content', $system['content'])->where('readOnly', false));
    }
});
