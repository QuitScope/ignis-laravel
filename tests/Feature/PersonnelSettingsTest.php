<?php

use App\Models\EmsQualification;
use App\Models\FireQualification;
use App\Models\Personnel;
use App\Models\PersonnelTitle;
use App\Models\Rank;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function settingsUser(string ...$perms): User
{
    $role = Role::create(['name' => 'T'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

dataset('lists', [
    'ranks' => ['ranks', Rank::class, ['priority' => 5, 'name' => 'Brandmeister', 'name_male' => 'Brandmeister', 'name_female' => 'Brandmeisterin', 'badge' => 'b.png', 'is_archive' => false]],
    'fire' => ['fire-qualifications', FireQualification::class, ['priority' => 5, 'short_name' => 'GF', 'name' => 'Gruppenführer', 'name_male' => 'Gruppenführer', 'name_female' => 'Gruppenführerin', 'is_none' => false]],
    'ems' => ['ems-qualifications', EmsQualification::class, ['priority' => 5, 'name' => 'Notfallsanitäter', 'name_male' => 'Notfallsanitäter', 'name_female' => 'Notfallsanitäterin', 'abbreviation' => 'NFS', 'is_none' => false, 'is_trainable' => true]],
    'specialties' => ['specialties', Specialty::class, ['number' => 42, 'name' => 'Taucher', 'is_disabled' => false]],
    'titles' => ['titles', PersonnelTitle::class, ['priority' => 5, 'name' => 'Dr.']],
]);

it('requires login, lists for personnel.view, manages only for admin', function (string $path) {
    $url = "/settings/personnel/{$path}";
    $this->get($url)->assertRedirect('/login');
    $this->post($url, [])->assertRedirect('/login');

    $this->actingAs(settingsUser('audit.view'))->get($url)->assertForbidden();
    $this->actingAs(settingsUser('personnel.view'))->get($url)->assertOk()
        ->assertInertia(fn (Assert $p) => $p->has('rows')->where('can.manage', false));
    $this->actingAs(settingsUser('admin'))->get($url)->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('can.manage', true));
    $this->actingAs(User::factory()->superAdmin()->create())->get($url)->assertOk();
})->with('lists');

it('answers 403 before 422 and to non-admins for every write', function (string $path, string $model, array $payload) {
    $row = $model::create($payload);

    foreach ([settingsUser('personnel.view'), settingsUser('audit.view')] as $u) {
        $this->actingAs($u)->post("/settings/personnel/{$path}", [])->assertForbidden();
        $this->actingAs($u)->put("/settings/personnel/{$path}/{$row->id}", $payload)->assertForbidden();
        $this->actingAs($u)->delete("/settings/personnel/{$path}/{$row->id}")->assertForbidden();
    }
    expect($model::count())->toBe(1)->and(Activity::count())->toBe(0);
})->with('lists');

it('creates, updates and deletes a row and audits each step', function (string $path, string $model, array $payload) {
    $module = ['ranks' => 'Dienstgrade', 'fire-qualifications' => 'FW-Qualifikationen', 'ems-qualifications' => 'RD-Qualifikationen', 'specialties' => 'Fachdienste', 'titles' => 'Titel'][$path];
    $admin = settingsUser('admin');
    $url = "/settings/personnel/{$path}";

    $this->actingAs($admin)->post($url, $payload)->assertRedirect();
    $row = $model::firstOrFail();
    foreach ($payload as $k => $v) {
        expect($row->getAttribute($k))->toBe($v);
    }

    $changed = array_merge($payload, isset($payload['number']) ? ['number' => 43, 'name' => 'Neu'] : ['name' => 'Neu']);
    $this->actingAs($admin)->put("{$url}/{$row->id}", $changed)->assertRedirect();
    expect($row->fresh()->name)->toBe('Neu');

    $this->actingAs($admin)->delete("{$url}/{$row->id}")->assertRedirect();
    expect($model::count())->toBe(0);

    $logs = Activity::orderBy('id')->get();
    expect($logs)->toHaveCount(3)
        ->and($logs->every(fn ($a) => $a->log_name === $module && $a->is_global && $a->causer_id === $admin->id))->toBeTrue()
        ->and($logs[0]->description)->toEndWith(' erstellt')
        ->and($logs[1]->description)->toContain(' aktualisiert [ID: '.$row->id.']')
        ->and($logs[2]->description)->toContain(' gelöscht [ID: '.$row->id.']')
        ->and($logs[1]->details)->toBe('Name: Neu')
        ->and($logs[1]->properties['context'])->toBe(['id' => $row->id]);
})->with('lists');

it('rejects invalid input per table', function (string $path, string $model, array $payload) {
    $admin = settingsUser('admin');
    $url = "/settings/personnel/{$path}";
    $bad = isset($payload['number'])
        ? [['name' => ''], ['name' => str_repeat('a', 256)], ['number' => 'x'], ['number' => 10000]]
        : [['name' => ''], ['name' => str_repeat('a', $path === 'titles' ? 51 : 256)], ['priority' => 'x'], ['priority' => 10000], ['priority' => -1], ['priority' => null]];
    foreach ($bad as $over) {
        $this->actingAs($admin)->postJson($url, array_merge($payload, $over))->assertUnprocessable()->assertJsonValidationErrors(array_keys($over));
    }
    foreach (['badge' => 256, 'abbreviation' => 51] as $field => $len) {
        if (isset($payload[$field])) {
            $this->actingAs($admin)->postJson($url, array_merge($payload, [$field => str_repeat('a', $len)]))->assertJsonValidationErrors($field);
        }
    }
    if (isset($payload['short_name'])) {
        $this->actingAs($admin)->postJson($url, array_merge($payload, ['short_name' => '']))->assertJsonValidationErrors('short_name');
    }
    expect($model::count())->toBe(0);
})->with('lists');

it('requires the gendered names of ranks', function () {
    $this->actingAs(settingsUser('admin'))->postJson('/settings/personnel/ranks', ['priority' => 1, 'name' => 'A'])
        ->assertJsonValidationErrors(['name_male', 'name_female']);
});

it('keeps specialty numbers and title names unique, ignoring the row itself and case', function () {
    $admin = settingsUser('admin');
    $a = Specialty::create(['number' => 1, 'name' => 'A']);
    Specialty::create(['number' => 2, 'name' => 'B']);

    $this->actingAs($admin)->putJson("/settings/personnel/specialties/{$a->id}", ['number' => 1, 'name' => 'A2'])->assertRedirect();
    $this->actingAs($admin)->putJson("/settings/personnel/specialties/{$a->id}", ['number' => 2, 'name' => 'A2'])->assertJsonValidationErrors('number');
    $this->actingAs($admin)->postJson('/settings/personnel/specialties', ['number' => 2, 'name' => 'C'])->assertJsonValidationErrors('number');

    $t = PersonnelTitle::create(['name' => 'Dr.', 'priority' => 1]);
    $this->actingAs($admin)->putJson("/settings/personnel/titles/{$t->id}", ['name' => 'DR.', 'priority' => 2])->assertRedirect();
    $this->actingAs($admin)->postJson('/settings/personnel/titles', ['name' => 'dr.', 'priority' => 3])->assertJsonValidationErrors('name');
});

it('404s for an unknown id and does not route non-numeric ids', function () {
    $admin = settingsUser('admin');
    $this->actingAs($admin)->delete('/settings/personnel/titles/999')->assertNotFound();
    $this->actingAs($admin)->put('/settings/personnel/titles/999', ['name' => 'x', 'priority' => 1])->assertNotFound();
    $this->actingAs($admin)->delete('/settings/personnel/titles/abc')->assertNotFound();
});

it('refuses to delete a row that personnel still uses', function () {
    $admin = settingsUser('admin');
    $title = PersonnelTitle::create(['name' => 'Dr.', 'priority' => 1]);
    $p = Personnel::factory()->create(['title_id' => $title->id]);

    foreach ([
        'titles' => $title,
        'ranks' => $p->rank,
        'fire-qualifications' => $p->fireQualification,
        'ems-qualifications' => $p->emsQualification,
    ] as $path => $row) {
        $this->actingAs($admin)->deleteJson("/settings/personnel/{$path}/{$row->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('delete');
        expect($row->fresh())->not->toBeNull();
    }
    expect(Activity::count())->toBe(0);
});

it('refuses to delete a specialty with pivot rows and names the count', function () {
    $admin = settingsUser('admin');
    $s = Specialty::create(['number' => 1, 'name' => 'A']);
    Personnel::factory()->create()->specialties()->attach($s->id);

    $res = $this->actingAs($admin)->deleteJson("/settings/personnel/specialties/{$s->id}")->assertUnprocessable();
    expect($res->json('errors.delete.0'))->toContain('1')->and($s->fresh())->not->toBeNull();
});

it('protects the none rows and the only archive rank from deletion', function () {
    $admin = settingsUser('admin');
    $fire = FireQualification::create(['priority' => 0, 'short_name' => '-', 'name' => 'Keine', 'name_male' => 'Keine', 'name_female' => 'Keine', 'is_none' => true]);
    $ems = EmsQualification::create(['priority' => 0, 'name' => 'Keine', 'name_male' => 'Keine', 'name_female' => 'Keine', 'is_none' => true]);
    $archive = Rank::factory()->archive()->create();

    $this->actingAs($admin)->deleteJson("/settings/personnel/fire-qualifications/{$fire->id}")->assertJsonValidationErrors('delete');
    $this->actingAs($admin)->deleteJson("/settings/personnel/ems-qualifications/{$ems->id}")->assertJsonValidationErrors('delete');
    $this->actingAs($admin)->deleteJson("/settings/personnel/ranks/{$archive->id}")->assertJsonValidationErrors('delete');
    expect([$fire->fresh(), $ems->fresh(), $archive->fresh()])->each->not->toBeNull();

    $second = Rank::factory()->archive()->create();
    $this->actingAs($admin)->deleteJson("/settings/personnel/ranks/{$second->id}")->assertRedirect();
});

it('allows several flagged rows but never unflags the last one', function () {
    $admin = settingsUser('admin');
    $none = FireQualification::create(['priority' => 0, 'short_name' => '-', 'name' => 'Keine', 'name_male' => 'Keine', 'name_female' => 'Keine', 'is_none' => true]);
    $payload = ['priority' => 0, 'short_name' => '-', 'name' => 'Keine', 'name_male' => 'Keine', 'name_female' => 'Keine', 'is_none' => false];

    $this->actingAs($admin)->putJson("/settings/personnel/fire-qualifications/{$none->id}", $payload)->assertJsonValidationErrors('is_none');
    expect($none->fresh()->is_none)->toBeTrue();

    $this->actingAs($admin)->postJson('/settings/personnel/fire-qualifications', array_merge($payload, ['name' => 'Zweite', 'is_none' => true]))->assertRedirect();
    $this->actingAs($admin)->putJson("/settings/personnel/fire-qualifications/{$none->id}", $payload)->assertRedirect();
    expect($none->fresh()->is_none)->toBeFalse();

    $archive = Rank::factory()->archive()->create();
    $this->actingAs($admin)->putJson("/settings/personnel/ranks/{$archive->id}", [
        'priority' => 1, 'name' => 'x', 'name_male' => 'x', 'name_female' => 'x', 'is_archive' => false,
    ])->assertJsonValidationErrors('is_archive');
});

it('passes rows with the usage count to the page', function () {
    $p = Personnel::factory()->create();
    $this->actingAs(settingsUser('admin'))->get('/settings/personnel/ranks')
        ->assertInertia(fn (Assert $page) => $page->component('Settings/Personnel/Ranks')
            ->where('rows.0.id', $p->rank_id)->where('rows.0.used', 1)->where('rows.0.name', $p->rank->name));
});

it('redirects the five legacy index pages', function (string $legacy, string $new) {
    $this->get("/settings/personnel/{$legacy}/index")->assertRedirect($new)->assertStatus(301);
})->with([
    ['ranks', '/settings/personnel/ranks'],
    ['fdskills', '/settings/personnel/fire-qualifications'],
    ['ambskills', '/settings/personnel/ems-qualifications'],
    ['specialties', '/settings/personnel/specialties'],
    ['titles', '/settings/personnel/titles'],
]);

it('maps a reference inserted just before the delete to a validation error, not a 500', function (string $path, string $model, Closure $reference) {
    $admin = settingsUser('admin');
    $row = match ($model) {
        Rank::class => Rank::factory()->create(),
        Specialty::class => Specialty::create(['number' => 7, 'name' => 'Race']),
    };
    $model::deleting(function ($deleting) use ($reference) {
        $reference($deleting);
    });

    $this->actingAs($admin)->deleteJson("/settings/personnel/{$path}/{$row->id}")
        ->assertUnprocessable()->assertJsonValidationErrors('delete');

    expect($model::find($row->id))->not->toBeNull()->and(Activity::count())->toBe(0);
})->with([
    'ranks' => ['ranks', Rank::class, fn ($r) => Personnel::factory()->create(['rank_id' => $r->id])],
    'specialties' => ['specialties', Specialty::class, fn ($s) => Personnel::factory()->create()->specialties()->attach($s->id)],
]);

it('gives row-specific accessible names to the edit and delete buttons', function () {
    expect(trans('lookup.edit_row', ['name' => 'X']))->toContain('X')
        ->and(trans('lookup.delete_row', ['name' => 'X']))->toContain('X');
});

it('answers 404 for an overflowing id on settings update and destroy', function (string $path) {
    $admin = settingsUser('admin');
    $this->actingAs($admin)->put("/settings/personnel/{$path}/".str_repeat('9', 25), ['name' => 'x'])->assertNotFound();
    $this->actingAs($admin)->delete("/settings/personnel/{$path}/".str_repeat('9', 25))->assertNotFound();
})->with(['ranks', 'fire-qualifications', 'ems-qualifications', 'specialties', 'titles']);
