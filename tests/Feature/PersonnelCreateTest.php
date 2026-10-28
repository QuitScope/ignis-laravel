<?php

use App\Events\PersonnelDeleted;
use App\Events\PersonnelSaved;
use App\Models\EmsQualification;
use App\Models\FireQualification;
use App\Models\Personnel;
use App\Models\PersonnelLogEntry;
use App\Models\Rank;
use App\Models\Role;
use App\Models\User;
use App\Support\ActorName;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->fire = FireQualification::create(['priority' => 0, 'short_name' => '-', 'name' => 'Keine', 'name_male' => 'Keine', 'name_female' => 'Keine', 'is_none' => true]);
    $this->ems = EmsQualification::create(['priority' => 0, 'name' => 'Keine', 'name_male' => 'Keine', 'name_female' => 'Keine', 'is_none' => true]);
    $this->rank = Rank::factory()->create();
});

afterEach(fn () => Personnel::flushEventListeners());

function hrUser(string ...$perms): User
{
    $role = Role::create(['name' => 'H'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function validPerson(array $over = []): array
{
    return $over + [
        'full_name' => 'Erika Muster', 'title_id' => null, 'birth_date' => '1990-05-06', 'gender' => 1,
        'discord_id' => '123456789012345678', 'phone' => '555-1234', 'service_number' => 'BF-100',
        'hired_at' => '2024-01-02', 'rank_id' => test()->rank->id,
    ];
}

it('enforces the permission matrix on every route', function () {
    $p = Personnel::factory()->create(['rank_id' => $this->rank->id]);
    $viewer = hrUser('personnel.view');
    $editor = hrUser('personnel.edit');
    $deleter = hrUser('personnel.delete');

    $this->get('/personnel/create')->assertRedirect('/login');
    $this->post('/personnel', validPerson())->assertRedirect('/login');
    $this->delete("/personnel/{$p->id}")->assertRedirect('/login');

    foreach ([$viewer, $deleter] as $u) {
        $this->actingAs($u)->get('/personnel/create')->assertForbidden();
        $this->actingAs($u)->post('/personnel', validPerson())->assertForbidden();
    }
    foreach ([$viewer, $editor] as $u) {
        $this->actingAs($u)->delete("/personnel/{$p->id}")->assertForbidden();
    }
    expect(Personnel::count())->toBe(1);

    $this->actingAs($editor)->get('/personnel/create')->assertOk();
    $this->actingAs(hrUser('admin'))->get('/personnel/create')->assertOk();
    $this->actingAs($deleter)->delete("/personnel/{$p->id}")->assertRedirect('/personnel');
});

it('serves the create form with non-archived ranks only', function () {
    Rank::factory()->create(['is_archive' => true]);

    $this->actingAs(hrUser('personnel.edit'))->get('/personnel/create')->assertInertia(fn (Assert $p) => $p
        ->component('Personnel/Create')->has('ranks', 1)->where('ranks.0.id', $this->rank->id)->where('requireCharacterId', false));
});

it('creates a person with defaults, history entry, event and audit', function () {
    Event::fake([PersonnelSaved::class]);
    $actor = hrUser('personnel.edit');

    $this->actingAs($actor)->post('/personnel', validPerson());

    $p = Personnel::firstOrFail();
    $this->assertAuthenticatedAs($actor);
    expect($p->service_number)->toBe('BF-100')->and($p->fire_qualification_id)->toBe($this->fire->id)->and($p->ems_qualification_id)->toBe($this->ems->id)
        ->and($p->birth_date->toDateString())->toBe('1990-05-06')->and($p->discord_id)->toBe('123456789012345678');

    $log = PersonnelLogEntry::where('personnel_id', $p->id)->sole();
    expect($log->type)->toBe(PersonnelLogEntry::CREATED)->and($log->content)->toBe('Mitarbeiterprofil wurde angelegt.')->and($log->author)->toBe(ActorName::for($actor));
    Event::assertDispatched(PersonnelSaved::class, fn ($e) => $e->personnelId === $p->id);

    $audit = Activity::sole();
    expect($audit->description)->toBe('Mitarbeiter erstellt')->and($audit->is_global)->toBeTruthy()->and($audit->causer_id)->toBe($actor->id)
        ->and($audit->details)->toContain('Erika Muster')->toContain('BF-100');
});

it('redirects to the new profile', function () {
    $r = $this->actingAs(hrUser('personnel.edit'))->post('/personnel', validPerson());

    $r->assertRedirect('/personnel/'.Personnel::sole()->id);
});

it('rejects invalid input', function () {
    foreach ([
        'no name' => [['full_name' => ''], 'full_name'],
        'long name' => [['full_name' => str_repeat('a', 256)], 'full_name'],
        'future birth date' => [['birth_date' => '2999-01-01'], 'birth_date'],
        'bad gender' => [['gender' => 3], 'gender'],
        'bad discord' => [['discord_id' => '12345'], 'discord_id'],
        'no number' => [['service_number' => ''], 'service_number'],
        'letters only' => [['service_number' => 'ABC'], 'service_number'],
        'bad chars' => [['service_number' => 'BF 1!'], 'service_number'],
        'bad hired date' => [['hired_at' => 'gestern'], 'hired_at'],
        'unknown rank' => [['rank_id' => 9999], 'rank_id'],
        'unknown title' => [['title_id' => 9999], 'title_id'],
    ] as [$override, $field]) {
        $this->actingAs(hrUser('personnel.edit'))->postJson('/personnel', validPerson($override))->assertUnprocessable()->assertJsonValidationErrors($field);
        expect(Personnel::count())->toBe(0);
    }
});

it('rejects an archive rank on creation', function () {
    $archive = Rank::factory()->create(['is_archive' => true]);

    $this->actingAs(hrUser('personnel.edit'))->postJson('/personnel', validPerson(['rank_id' => $archive->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('rank_id');
});

it('rejects a duplicate service number', function () {
    Personnel::factory()->create(['service_number' => 'BF-100', 'rank_id' => $this->rank->id]);

    $this->actingAs(hrUser('personnel.edit'))->postJson('/personnel', validPerson())->assertUnprocessable()->assertJsonValidationErrors('service_number');
    expect(Personnel::count())->toBe(1);
});

it('answers 422 instead of 500 when the number is taken between validation and insert', function () {
    Event::fake([PersonnelSaved::class]);
    $rank = $this->rank;
    $fire = $this->fire;
    $ems = $this->ems;
    Personnel::creating(function () use ($rank, $fire, $ems) {
        DB::table('personnel')->insert(['full_name' => 'Rennen', 'birth_date' => '1990-01-01', 'gender' => 0, 'service_number' => 'BF-100', 'hired_at' => '2020-01-01',
            'rank_id' => $rank->id, 'fire_qualification_id' => $fire->id, 'ems_qualification_id' => $ems->id]);
    });

    $this->actingAs(hrUser('personnel.edit'))->postJson('/personnel', validPerson())->assertUnprocessable()->assertJsonValidationErrors('service_number');
    expect(Personnel::count())->toBe(0)->and(PersonnelLogEntry::count())->toBe(0)->and(Activity::count())->toBe(0);
    Event::assertNotDispatched(PersonnelSaved::class);
});

it('requires the character id only when the setting says so', function () {
    $u = hrUser('personnel.edit');
    $this->actingAs($u)->postJson('/personnel', validPerson())->assertRedirect();
    Personnel::query()->delete();

    app(Settings::class)->set('personnel.require_character_id', true);
    $this->actingAs($u)->get('/personnel/create')->assertInertia(fn (Assert $p) => $p->where('requireCharacterId', true));
    $this->actingAs($u)->postJson('/personnel', validPerson())->assertUnprocessable()->assertJsonValidationErrors('character_id');
    $this->actingAs($u)->postJson('/personnel', validPerson(['character_id' => 'ABC123']))->assertRedirect();
    expect(Personnel::sole()->character_id)->toBe('ABC123');
});

it('deletes a person with history, unlinks the account, fires the event and audits', function () {
    Event::fake([PersonnelDeleted::class]);
    $p = Personnel::factory()->create(['rank_id' => $this->rank->id]);
    PersonnelLogEntry::create(['personnel_id' => $p->id, 'type' => PersonnelLogEntry::NOTE, 'content' => 'x', 'author' => 'y']);
    $account = User::factory()->create(['personnel_id' => $p->id]);
    $actor = hrUser('personnel.delete');

    $this->actingAs($actor)->delete("/personnel/{$p->id}")->assertRedirect('/personnel');

    expect(Personnel::count())->toBe(0)->and(PersonnelLogEntry::count())->toBe(0)->and($account->fresh()->personnel_id)->toBeNull();
    Event::assertDispatched(PersonnelDeleted::class, fn ($e) => $e->personnelId === $p->id);
    $audit = Activity::sole();
    expect($audit->description)->toBe("Mitarbeiter gelöscht [ID: {$p->id}]")->and($audit->is_global)->toBeTruthy()->and($audit->causer_id)->toBe($actor->id);
});

it('removes the generated picture file with the record but never a legacy value', function () {
    Storage::fake('profile-pictures');
    $name = str_repeat('a', 32).'.png';
    Storage::disk('profile-pictures')->put($name, 'x');
    Storage::disk('profile-pictures')->put('legacy.png', 'x');
    $p = Personnel::factory()->create(['rank_id' => $this->rank->id, 'profile_picture' => $name]);
    $legacy = Personnel::factory()->create(['rank_id' => $this->rank->id, 'profile_picture' => 'legacy.png']);
    $actor = hrUser('personnel.delete');

    $this->actingAs($actor)->delete("/personnel/{$p->id}")->assertRedirect('/personnel');
    $this->actingAs($actor)->delete("/personnel/{$legacy->id}")->assertRedirect('/personnel');

    Storage::disk('profile-pictures')->assertMissing($name);
    Storage::disk('profile-pictures')->assertExists('legacy.png');
});

it('answers 404 for an unknown person on delete', function () {
    $this->actingAs(hrUser('personnel.delete'))->delete('/personnel/999')->assertNotFound();
});

it('names the actor from the linked file, the account name or the id', function () {
    $linked = User::factory()->create(['name' => 'Konto', 'personnel_id' => Personnel::factory()->create(['full_name' => 'Akte Name', 'rank_id' => $this->rank->id])->id]);
    expect(ActorName::for($linked))->toBe('Akte Name')
        ->and(ActorName::for(User::factory()->create(['name' => 'Nur Konto'])))->toBe('Nur Konto');
    $bare = User::factory()->create(['name' => null]);
    expect(ActorName::for($bare))->toBe("Admin #{$bare->id}")->and(ActorName::for(null))->toBe('Admin #0');
});

// ----- service number checks -----

it('checks a service number as JSON, with the legacy answer shapes', function () {
    $p = Personnel::factory()->create(['service_number' => 'BF-1', 'rank_id' => $this->rank->id]);
    $u = hrUser('personnel.view');

    $this->postJson('/api/personnel/check-dienstnr', ['dienstnr' => 'BF-1'])->assertUnauthorized();
    $this->actingAs($u)->postJson('/api/personnel/check-dienstnr', ['dienstnr' => ' BF-1 '])->assertOk()
        ->assertExactJson(['available' => false, 'dienstnr' => 'BF-1', 'message' => 'Dienstnummer bereits vergeben']);
    $this->actingAs($u)->postJson('/api/personnel/check-dienstnr', ['dienstnr' => 'BF-2'])->assertOk()
        ->assertExactJson(['available' => true, 'dienstnr' => 'BF-2', 'message' => 'Dienstnummer verfügbar']);
    $this->actingAs($u)->postJson('/api/personnel/check-dienstnr', ['dienstnr' => 'BF-1', 'exclude_id' => $p->id])->assertOk()->assertJsonPath('available', true);
    $this->actingAs($u)->postJson('/api/personnel/check-dienstnr', ['dienstnr' => 'ABC'])->assertOk()
        ->assertExactJson(['error' => 'Ungültiges Format für Dienstnummer. Muss mindestens eine Zahl enthalten.']);
    $this->actingAs($u)->postJson('/api/personnel/check-dienstnr', [])->assertOk()->assertExactJson(['error' => 'Keine Dienstnummer angegeben']);
});

it('answers the legacy form check with plain text', function () {
    $p = Personnel::factory()->create(['service_number' => 'BF-1', 'rank_id' => $this->rank->id]);
    $u = hrUser('personnel.view');
    $check = fn (array $d) => $this->actingAs($u)->post('/api/personnel/check-dienstnr-legacy', $d)->assertOk()->getContent();

    expect($check(['dienstnr' => 'BF-1']))->toBe('exists')
        ->and($check(['dienstnr' => 'BF-1', 'exclude_id' => $p->id]))->toBe('not_exists')
        ->and($check(['dienstnr' => 'BF-2']))->toBe('not_exists')
        ->and($check(['dienstnr' => 'nope']))->toBe('error')
        ->and($check([]))->toBe('error');
});

it('throttles the checks', function () {
    $u = hrUser('personnel.view');
    for ($i = 0; $i < 60; $i++) {
        $this->actingAs($u)->postJson('/api/personnel/check-dienstnr', ['dienstnr' => '1'])->assertOk();
    }
    $this->actingAs($u)->postJson('/api/personnel/check-dienstnr', ['dienstnr' => '1'])->assertStatus(429);
});

it('rewrites the legacy function URLs internally, keeping POST', function (string $from) {
    Personnel::factory()->create(['service_number' => 'BF-1', 'rank_id' => $this->rank->id]);

    $this->actingAs(hrUser('personnel.view'))->post($from, ['dienstnr' => 'BF-1'])->assertOk();
})->with(['/assets/functions/checkdnr.php', '/assets/functions/checkdnr', '/assets/functions/checkdienstnr2.php']);

it('redirects the legacy list URL with its query translated', function (string $from, string $to) {
    $r = $this->get($from);
    $r->assertStatus(301);
    expect($r->headers->get('Location'))->toBe($to);
})->with([
    ['/personnel/list', '/personnel'],
    ['/mitarbeiter/list', '/personnel'],
    ['/personnel/list?archiv=1', '/personnel?archive=1'],
    ['/personnel/list?archiv', '/personnel?archive=1'],
    ['/personnel/list?q=Max&dg=3&rd=2&fw=1', '/personnel?search=Max&rank=3&ems=2&fire=1'],
    ['/personnel/list?sort=dienstnr&dir=desc&page=2', '/personnel?sort=service_number&dir=desc&page=2'],
]);

it('treats an array service number as missing instead of failing', function () {
    $u = hrUser('personnel.view');
    $this->actingAs($u)->postJson('/api/personnel/check-dienstnr', ['dienstnr' => ['x']])->assertOk()->assertJson(['error' => 'Keine Dienstnummer angegeben']);
    $this->actingAs($u)->post('/api/personnel/check-dienstnr-legacy', ['dienstnr' => ['x']])->assertOk()->assertSee('error', false);
});
