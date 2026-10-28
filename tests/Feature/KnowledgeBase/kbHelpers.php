<?php

use App\Models\Role;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Testing\TestResponse;
use Modules\KnowledgeBase\Models\KbCategory;
use Modules\KnowledgeBase\Models\KbEntry;
use Modules\KnowledgeBase\Models\KbTag;

function kbx_user(string ...$perms): User
{
    $role = Role::create(['name' => 'R'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

/** @param array<string, mixed> $attrs */
function kbx_entry(array $attrs = []): KbEntry
{
    $e = (new KbEntry)->forceFill($attrs + ['type' => 'general', 'title' => 'Titel '.uniqid(), 'search_text' => 'x']);
    $e->save();

    return $e;
}

function kbx_category(string $name, ?int $parent = null): KbCategory
{
    $c = (new KbCategory)->forceFill(['name' => $name, 'parent_id' => $parent, 'sort_order' => 0]);
    $c->save();

    return $c;
}

function kbx_tag(string $name): KbTag
{
    $t = (new KbTag)->forceFill(['name' => $name, 'color' => '#336699']);
    $t->save();

    return $t;
}

function kbx_public(bool $on = true): void
{
    app(Settings::class)->set('knowledgebase.public_access', $on);
}

function kbx_doc(string $text = 'Hallo'): string
{
    return json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]]);
}

/** @param array<string, mixed> $props */
function kbx_props(TestResponse $r): array
{
    return $r->viewData('page')['props'];
}
