<?php

use App\Models\Role;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\KnowledgeBase\Models\KbCategory;
use Modules\KnowledgeBase\Models\KbEntry;
use Modules\KnowledgeBase\Models\KbTag;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function kb_user(string ...$perms): User
{
    $role = Role::create(['name' => 'K'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function kb_cat(string $name, ?KbCategory $parent = null, int $sort = 0): KbCategory
{
    return tap((new KbCategory)->forceFill(['name' => $name, 'parent_id' => $parent?->id, 'sort_order' => $sort, 'icon' => null]))->save();
}

function kb_entry(?KbCategory $cat = null, bool $archived = false): KbEntry
{
    return tap((new KbEntry)->forceFill(['type' => 'general', 'title' => 'T', 'category_id' => $cat?->id, 'search_text' => 't', 'is_archived' => $archived]))->save();
}

function kb_tag(string $name): KbTag
{
    return tap((new KbTag)->forceFill(['name' => $name, 'color' => '#112233']))->save();
}

it('sends guests to the login and refuses accounts without kb.edit on every route', function () {
    $c = kb_cat('A');
    $t = kb_tag('x');
    $calls = [
        ['get', '/lexicon/taxonomy'], ['post', '/lexicon/categories'], ['put', "/lexicon/categories/{$c->id}"], ['delete', "/lexicon/categories/{$c->id}"],
        ['post', '/lexicon/tags'], ['put', "/lexicon/tags/{$t->id}"], ['delete', "/lexicon/tags/{$t->id}"],
    ];
    foreach ($calls as [$m, $url]) {
        $this->{$m}($url)->assertRedirect('/login');
    }
    foreach ([kb_user('kb.archive'), kb_user('audit.view')] as $user) {
        foreach ($calls as [$m, $url]) {
            $this->actingAs($user)->{$m.'Json'}($url, [])->assertForbidden();
        }
    }
    expect(KbCategory::count())->toBe(1)->and(KbTag::count())->toBe(1);
});

it('lets kb.edit, admin and super admin in', function () {
    foreach ([kb_user('kb.edit'), kb_user('admin'), User::factory()->superAdmin()->create()] as $user) {
        $this->actingAs($user)->get('/lexicon/taxonomy')->assertOk()->assertInertia(fn (Assert $p) => $p->component('KnowledgeBase/Taxonomy'));
        $this->actingAs($user)->postJson('/lexicon/tags', ['name' => 'T'.uniqid(), 'color' => '#aabbcc'])->assertSuccessful();
    }
});

it('creates, edits and deletes a category with audit', function () {
    $u = kb_user('kb.edit');
    $id = $this->actingAs($u)->postJson('/lexicon/categories', ['name' => '  Medikamente ', 'icon' => 'pill', 'sort_order' => 3])->assertSuccessful()->json('id');
    $c = KbCategory::findOrFail($id);
    expect($c->name)->toBe('Medikamente')->and($c->icon)->toBe('pill')->and($c->sort_order)->toBe(3)->and($c->parent_id)->toBeNull();

    $this->actingAs($u)->putJson("/lexicon/categories/{$id}", ['name' => 'Arzneien', 'icon' => null, 'sort_order' => 1, 'parent_id' => null])->assertSuccessful();
    expect($c->fresh()->name)->toBe('Arzneien')->and($c->fresh()->icon)->toBeNull();
    $this->actingAs($u)->deleteJson("/lexicon/categories/{$id}")->assertSuccessful();
    expect(KbCategory::count())->toBe(0);

    $logs = Activity::query()->where('log_name', 'Wissensdatenbank')->orderBy('id')->pluck('description')->map(fn ($d) => explode(' [', $d)[0])->all();
    expect($logs)->toBe(['Kategorie erstellt', 'Kategorie bearbeitet', 'Kategorie gelöscht']);
});

it('validates category fields', function (array $body, string $field) {
    $this->actingAs(kb_user('kb.edit'))->postJson('/lexicon/categories', $body)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'no name' => [['name' => ''], 'name'],
    'long name' => [['name' => str_repeat('x', 101)], 'name'],
    'array name' => [['name' => ['a']], 'name'],
    'bad icon' => [['name' => 'a', 'icon' => '<b>'], 'icon'],
    'unknown parent' => [['name' => 'a', 'parent_id' => 999], 'parent_id'],
    'negative sort' => [['name' => 'a', 'sort_order' => -1], 'sort_order'],
    'text sort' => [['name' => 'a', 'sort_order' => 'x'], 'sort_order'],
]);

it('refuses a category as its own descendant', function () {
    $u = kb_user('kb.edit');
    $a = kb_cat('A');
    $b = kb_cat('B', $a);
    $c = kb_cat('C', $b);
    $body = fn ($cat, $parent) => ['name' => $cat->name, 'parent_id' => $parent->id, 'sort_order' => 0];

    $this->actingAs($u)->putJson("/lexicon/categories/{$a->id}", $body($a, $a))->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    $this->actingAs($u)->putJson("/lexicon/categories/{$a->id}", $body($a, $b))->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    $this->actingAs($u)->putJson("/lexicon/categories/{$a->id}", $body($a, $c))->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    expect($a->fresh()->parent_id)->toBeNull();

    $this->actingAs($u)->putJson("/lexicon/categories/{$c->id}", $body($c, $a))->assertSuccessful();
    expect($c->fresh()->parent_id)->toBe($a->id);
});

it('does not loop on a cycle that is already in the data', function () {
    $u = kb_user('kb.edit');
    $a = kb_cat('A');
    $b = kb_cat('B', $a);
    $c = kb_cat('C');
    $a->forceFill(['parent_id' => $b->id])->save();
    $this->actingAs($u)->putJson("/lexicon/categories/{$c->id}", ['name' => 'C', 'parent_id' => $a->id])->assertSuccessful();
    $this->actingAs($u)->putJson("/lexicon/categories/{$a->id}", ['name' => 'A', 'parent_id' => $a->id])->assertUnprocessable();
});

it('detaches children and entries when a category is deleted', function () {
    $u = kb_user('kb.edit');
    $a = kb_cat('A');
    $b = kb_cat('B', $a);
    $e = kb_entry($a);
    $this->actingAs($u)->deleteJson("/lexicon/categories/{$a->id}")->assertSuccessful();
    expect($b->fresh()->parent_id)->toBeNull()->and($e->fresh()->category_id)->toBeNull()->and(KbEntry::count())->toBe(1);
});

it('lists categories and tags in sort order with their usage', function () {
    $b = kb_cat('B', null, 2);
    kb_cat('A2', null, 1);
    kb_cat('A1', null, 1);
    kb_entry($b);
    $t = kb_tag('zeta');
    kb_tag('Alpha');
    $this->actingAs(kb_user('kb.edit'))->get('/lexicon/taxonomy')->assertInertia(fn (Assert $p) => $p
        ->where('categories.0.name', 'A1')->where('categories.1.name', 'A2')->where('categories.2.name', 'B')
        ->where('categories.2.entries_count', 1)->where('categories.0.children_count', 0)
        ->where('tags.0.name', 'Alpha')->where('tags.1.name', 'zeta')->where('tags.1.id', $t->id));
});

it('creates and edits tags, normalising the colour', function () {
    $u = kb_user('kb.edit');
    $id = $this->actingAs($u)->postJson('/lexicon/tags', ['name' => ' Notfall ', 'color' => '#AABBCC'])->assertSuccessful()->json('id');
    expect(KbTag::find($id)->name)->toBe('Notfall')->and(KbTag::find($id)->color)->toBe('#aabbcc');
    $this->actingAs($u)->putJson("/lexicon/tags/{$id}", ['name' => 'NOTFALL', 'color' => '#000000'])->assertSuccessful();
    expect(KbTag::find($id)->name)->toBe('NOTFALL');
    $this->actingAs($u)->deleteJson("/lexicon/tags/{$id}")->assertSuccessful();
    expect(KbTag::count())->toBe(0)
        ->and(Activity::where('log_name', 'Wissensdatenbank')->orderBy('id')->pluck('description')->map(fn ($d) => explode(' [', $d)[0])->all())->toBe(['Tag erstellt', 'Tag bearbeitet', 'Tag gelöscht']);
});

it('answers 409 with a message for a duplicate tag name, whatever the case', function () {
    $u = kb_user('kb.edit');
    kb_tag('Notfall');
    $other = kb_tag('Anderes');
    $this->actingAs($u)->postJson('/lexicon/tags', ['name' => 'notfall', 'color' => '#112233'])->assertStatus(409)->assertJsonStructure(['message']);
    $this->actingAs($u)->putJson("/lexicon/tags/{$other->id}", ['name' => 'NOTFALL', 'color' => '#112233'])->assertStatus(409)->assertJsonStructure(['message']);
    expect(KbTag::count())->toBe(2)->and($other->fresh()->name)->toBe('Anderes');
});

it('validates the tag colour and name', function (array $body, string $field) {
    $this->actingAs(kb_user('kb.edit'))->postJson('/lexicon/tags', $body)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'no colour' => [['name' => 'a'], 'color'],
    'short colour' => [['name' => 'a', 'color' => '#abc'], 'color'],
    'no hash' => [['name' => 'a', 'color' => 'aabbcc'], 'color'],
    'named colour' => [['name' => 'a', 'color' => 'red'], 'color'],
    'css injection' => [['name' => 'a', 'color' => '#aabbcc;x'], 'color'],
    'no name' => [['name' => '', 'color' => '#aabbcc'], 'name'],
    'long name' => [['name' => str_repeat('x', 51), 'color' => '#aabbcc'], 'name'],
]);

it('answers 404 with the module switched off, whatever the rights', function () {
    $c = kb_cat('A');
    app(Settings::class)->set('modules.knowledgebase.enabled', false);
    $admin = User::factory()->superAdmin()->create();
    $this->get('/lexicon/taxonomy')->assertNotFound();
    $this->actingAs($admin)->get('/lexicon/taxonomy')->assertNotFound();
    $this->actingAs($admin)->postJson('/lexicon/categories', ['name' => 'x'])->assertNotFound();
    $this->actingAs($admin)->deleteJson("/lexicon/categories/{$c->id}")->assertNotFound();
    $this->actingAs($admin)->postJson('/lexicon/tags', ['name' => 'x', 'color' => '#aabbcc'])->assertNotFound();
    expect(KbCategory::count())->toBe(1);
});

it('keeps de and en module labels in parity', function () {
    $de = json_decode(file_get_contents(base_path('modules/KnowledgeBase/lang/de.json')), true);
    $en = json_decode(file_get_contents(base_path('modules/KnowledgeBase/lang/en.json')), true);
    expect(array_keys($de))->toEqualCanonicalizing(array_keys($en));
});

it('treats an explicit null sort_order as default on create and as unchanged on update', function () {
    $u = kb_user('kb.edit');
    $id = $this->actingAs($u)->postJson('/lexicon/categories', ['name' => 'x', 'sort_order' => null, 'parent_id' => null, 'icon' => null])->assertSuccessful()->json('id');
    expect(KbCategory::find($id)->sort_order)->toBe(0);
    KbCategory::whereKey($id)->update(['sort_order' => 7]);
    $this->actingAs($u)->putJson("/lexicon/categories/{$id}", ['name' => 'y', 'sort_order' => null])->assertSuccessful();
    expect(KbCategory::find($id)->sort_order)->toBe(7)->and(KbCategory::find($id)->name)->toBe('y');
});
