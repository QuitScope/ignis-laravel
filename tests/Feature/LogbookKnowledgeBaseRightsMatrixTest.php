<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\KnowledgeBase\Models\KbCategory;
use Modules\KnowledgeBase\Models\KbEntry;
use Modules\KnowledgeBase\Models\KbTag;
use Modules\Logbook\Models\LogbookEntry;

uses(RefreshDatabase::class);

const P6_FILE = '0123456789abcdef0123456789abcdef.png';

function p6_actor(string $name): ?User
{
    if ($name === 'guest') {
        return null;
    }
    if ($name === 'super-admin') {
        return User::factory()->superAdmin()->create();
    }
    $role = Role::create(['name' => 'M'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions([$name === 'plain' ? 'audit.view' : $name]);

    return User::factory()->withRole($role)->create();
}

/** @param array<string, mixed> $attrs */
function p6_entry(array $attrs): KbEntry
{
    $e = (new KbEntry)->forceFill($attrs + ['type' => 'general', 'search_text' => 'x']);
    $e->save();

    return $e;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('kb-images');
    Storage::disk('kb-images')->put(P6_FILE, 'x');
});

it('answers every logbook and knowledge base route with the exact status for every account', function (string $actor, bool $public) {
    app(Settings::class)->set('knowledgebase.public_access', $public);
    $user = p6_actor($actor);
    $guest = $user === null;
    $in = fn (string ...$who) => in_array($actor, [...$who, 'admin', 'super-admin'], true);

    $open = p6_entry(['title' => 'Offen']);
    $old = p6_entry(['title' => 'Alt', 'is_archived' => true]);
    $target = p6_entry(['title' => 'Ziel']);
    $vehicle = Vehicle::factory()->create();
    $log = (new LogbookEntry)->forceFill(['vehicle_id' => $vehicle->id, 'vehicle_identifier' => $vehicle->identifier, 'trip_date' => '2026-10-20',
        'departed_at' => '08:30', 'arrived_at' => '09:00', 'distance_km' => 10, 'trip_type' => 'dienst', 'driver_name' => 'A', 'source' => 'admin']);
    $log->save();
    $cat = (new KbCategory)->forceFill(['name' => 'C', 'sort_order' => 0]);
    $cat->save();
    $cat2 = (new KbCategory)->forceFill(['name' => 'C2', 'sort_order' => 0]);
    $cat2->save();
    $tag = (new KbTag)->forceFill(['name' => 'T', 'color' => '#336699']);
    $tag->save();
    $tag2 = (new KbTag)->forceFill(['name' => 'T2', 'color' => '#336699']);
    $tag2->save();

    $bad = [];
    $check = function (string $label, string $method, string $url, int|string $expected, bool $asJson = false, array $data = []) use ($user, &$bad) {
        $this->app['auth']->forgetGuards();
        $request = $user === null ? $this : $this->actingAs($user);
        $response = $request->call($method, $url, $data, [], [], $asJson ? ['HTTP_ACCEPT' => 'application/json'] : []);
        $got = $expected === 'login' && $response->isRedirect(url('/login')) ? 'login' : $response->getStatusCode();
        if ($got !== $expected) {
            $bad[] = "{$label}: {$method} {$url} expected {$expected}, got {$got}";
        }
    };
    $page = fn (bool $allowed) => $allowed ? 200 : ($guest ? 'login' : 403);
    $write = fn (bool $allowed, int $ok = 422) => $allowed ? $ok : ($guest ? 401 : 403);

    $read = ! $guest || $public ? 200 : 'login';
    $check('index', 'GET', '/lexicon', $read);
    $check('show', 'GET', "/lexicon/{$open->id}", $read);
    $check('show-archived', 'GET', "/lexicon/{$old->id}", $in('kb.archive') ? 200 : ($guest && ! $public ? 'login' : 404));
    $check('show-missing', 'GET', '/lexicon/999999', $read === 200 ? 404 : 'login');
    $check('image', 'GET', '/storage/kb-images/'.P6_FILE, $guest && ! $public ? 'login' : 200);
    $check('image-bad-name', 'GET', '/storage/kb-images/notahash.png', 404);

    $check('create', 'GET', '/lexicon/create', $page($in('kb.edit')));
    $check('edit', 'GET', "/lexicon/{$open->id}/edit", $page($in('kb.edit')));
    $check('edit-archived', 'GET', "/lexicon/{$old->id}/edit", $in('kb.edit') ? ($in('kb.archive') ? 200 : 404) : ($guest ? 'login' : 403));
    $check('store', 'POST', '/lexicon', $write($in('kb.edit')), true);
    $check('update', 'PUT', "/lexicon/{$open->id}", $write($in('kb.edit'), 302), true);
    $check('lookup', 'GET', '/lexicon/lookup', $in('kb.edit') ? 200 : ($guest ? 401 : 403), true);
    $check('taxonomy', 'GET', '/lexicon/taxonomy', $page($in('kb.edit')));
    $check('category-store', 'POST', '/lexicon/categories', $write($in('kb.edit')), true);
    $check('category-update', 'PUT', "/lexicon/categories/{$cat->id}", $write($in('kb.edit')), true);
    $check('tag-store', 'POST', '/lexicon/tags', $write($in('kb.edit')), true);
    $check('tag-update', 'PUT', "/lexicon/tags/{$tag->id}", $write($in('kb.edit')), true);
    $check('image-upload', 'POST', '/lexicon/images', $write($in('kb.edit')), true);
    $check('pin', 'PATCH', "/lexicon/{$target->id}/pin", $write($in('kb.edit'), 302), true, ['value' => true]);

    $check('archive', 'PATCH', "/lexicon/{$target->id}/archive", $write($in('kb.archive'), 302), true, ['value' => true]);
    $check('hide-editor', 'PATCH', "/lexicon/{$target->id}/hide-editor", $write($in(), 302), true, ['value' => true]);

    $check('category-delete', 'DELETE', "/lexicon/categories/{$cat2->id}", $write($in('kb.edit'), 200), true);
    $check('tag-delete', 'DELETE', "/lexicon/tags/{$tag2->id}", $write($in('kb.edit'), 200), true);

    $check('logbook', 'GET', '/logbook', $page($in('logbook.view', 'logbook.manage')));
    $check('logbook-store', 'POST', '/logbook', $write($in('logbook.manage')), true);
    $check('logbook-update', 'PUT', "/logbook/{$log->id}", $write($in('logbook.manage')), true);
    $check('logbook-delete', 'DELETE', "/logbook/{$log->id}", $write($in('logbook.manage'), 302), true);

    expect($bad)->toBe([]);
})->with(function () {
    // public access only changes what a guest may read; one signed-in row with it on proves it grants nothing more
    foreach (['guest', 'plain', 'kb.edit', 'kb.archive', 'logbook.view', 'logbook.manage', 'admin', 'super-admin'] as $actor) {
        yield "{$actor} / public off" => [$actor, false];
    }
    yield 'guest / public on' => ['guest', true];
    yield 'plain / public on' => ['plain', true];
});
