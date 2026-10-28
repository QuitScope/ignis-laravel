<?php

use App\Events\PersonnelSaved;
use App\Http\Requests\StorePersonnelRequest;
use App\Models\EmsQualification;
use App\Models\FireQualification;
use App\Models\Personnel;
use App\Models\PersonnelLogEntry;
use App\Models\Rank;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\User;
use App\Support\ActorName;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->rank = Rank::factory()->create();
    $this->person = Personnel::factory()->create(['rank_id' => $this->rank->id, 'service_number' => 'BF-1', 'full_name' => 'Erika Muster', 'gender' => 1]);
});

function prUser(string ...$perms): User
{
    $role = Role::create(['name' => 'R'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function putBody(array $over = []): array
{
    $p = test()->person->fresh();

    return $over + [
        'full_name' => $p->full_name, 'title_id' => null, 'birth_date' => $p->birth_date->toDateString(), 'gender' => $p->gender,
        'discord_id' => null, 'phone' => null, 'service_number' => $p->service_number, 'hired_at' => $p->hired_at->toDateString(),
        'rank_id' => $p->rank_id, 'fire_qualification_id' => $p->fire_qualification_id, 'ems_qualification_id' => $p->ems_qualification_id,
        'character_id' => null, 'extra_qualification' => null,
    ];
}

function note(Personnel $p, int $type = 0, string $content = 'Text'): PersonnelLogEntry
{
    return PersonnelLogEntry::create(['personnel_id' => $p->id, 'type' => $type, 'content' => $content, 'author' => 'X']);
}

it('enforces the permission matrix on every route', function () {
    $p = $this->person;
    $entry = note($p);
    $viewer = prUser('personnel.view');
    $editor = prUser('personnel.edit');
    $commenter = prUser('personnel.view', 'personnel.comment.delete');
    $routes = [
        ['get', "/personnel/{$p->id}", []],
        ['put', "/personnel/{$p->id}", putBody()],
        ['put', "/personnel/{$p->id}/specialties", ['specialties' => []]],
        ['post', "/personnel/{$p->id}/notes", ['type' => 0, 'content' => 'x']],
        ['delete', "/personnel/{$p->id}/notes/{$entry->id}", []],
    ];

    foreach ($routes as [$verb, $url, $body]) {
        $this->{$verb}($url, $body)->assertRedirect('/login');
    }
    foreach (array_slice($routes, 1) as [$verb, $url, $body]) {
        $this->actingAs($viewer)->{$verb}($url, $body)->assertForbidden();
    }
    $this->actingAs($editor)->get("/personnel/{$p->id}")->assertForbidden();
    $this->actingAs($editor)->delete("/personnel/{$p->id}/notes/{$entry->id}")->assertForbidden();
    $this->actingAs($editor)->post("/personnel/{$p->id}/notes", ['type' => 0, 'content' => 'x'])->assertRedirect();
    $this->actingAs($commenter)->post("/personnel/{$p->id}/notes", ['type' => 0, 'content' => 'x'])->assertForbidden();
    $this->actingAs($commenter)->delete("/personnel/{$p->id}/notes/{$entry->id}")->assertRedirect();
    $this->actingAs(prUser('admin'))->get("/personnel/{$p->id}")->assertOk();
    $this->actingAs($viewer)->get('/personnel/999999')->assertNotFound();
});

it('serves the profile with its relations and few queries', function () {
    $s = Specialty::create(['number' => 1, 'name' => 'Taucher', 'is_disabled' => false]);
    $this->person->specialties()->attach($s);
    foreach (range(1, 8) as $i) {
        note($this->person, $i % 3, "N$i");
        note($this->person, 5, "H$i");
    }
    User::factory()->create(['personnel_id' => $this->person->id, 'username' => 'erika']);

    $actor = prUser('personnel.view', 'personnel.edit');
    DB::enableQueryLog();
    $this->actingAs($actor)->get("/personnel/{$this->person->id}")->assertInertia(fn (Assert $p) => $p
        ->component('Personnel/Show')
        ->where('person.service_number', 'BF-1')->where('person.specialty_ids', [$s->id])
        ->where('person.rank.label', $this->rank->name_female)
        ->where('account.username', 'erika')
        ->has('notes.data', 6)->where('notes.total', 8)->has('history.data', 6)->where('history.total', 8)
        ->where('can.update', true)->where('can.delete_comments', false)
        ->has('ranks')->has('specialties', 1));
    expect(count(DB::getQueryLog()))->toBeLessThan(25);
});

it('pages notes and history separately and keeps the tab', function () {
    foreach (range(1, 8) as $i) {
        note($this->person, 0, "N$i");
        note($this->person, 5, "H$i");
    }
    $u = prUser('personnel.view');

    $this->actingAs($u)->get("/personnel/{$this->person->id}?notes_page=2&tab=history")->assertInertia(fn (Assert $p) => $p
        ->has('notes.data', 2)->has('history.data', 6)->where('tab', 'history')
        ->where('history.next_page_url', fn ($url) => str_contains($url, 'tab=history')));
    $this->actingAs($u)->get("/personnel/{$this->person->id}?tab=bogus")->assertInertia(fn (Assert $p) => $p->where('tab', 'notes'));
});

it('flags only note types as deletable and passes content raw in the props', function () {
    note($this->person, 0, '<script>alert(1)</script>');
    note($this->person, 5, 'System');

    $this->actingAs(prUser('personnel.view', 'personnel.comment.delete'))->get("/personnel/{$this->person->id}")->assertInertia(fn (Assert $p) => $p
        ->where('notes.data.0.content', '<script>alert(1)</script>')->where('notes.data.0.can_delete', true)
        ->where('history.data.0.can_delete', false)->where('can.delete_comments', true));
});

it('never renders raw HTML in the personnel pages', function () {
    foreach (['Show.vue', 'Index.vue', 'Create.vue'] as $f) {
        expect(file_get_contents(resource_path("js/Pages/Personnel/$f")))->not->toContain('v-html');
    }
    foreach (glob(resource_path('js/components/personnel/*.vue')) as $f) {
        expect(file_get_contents($f))->not->toContain('v-html');
    }
});

it('writes exactly the expected history entries on update', function () {
    $actor = prUser('personnel.view', 'personnel.edit');
    $newRank = Rank::factory()->create(['name' => 'Neu']);
    $url = "/personnel/{$this->person->id}";

    $this->actingAs($actor)->put($url, putBody())->assertRedirect($url);
    expect(PersonnelLogEntry::count())->toBe(0);

    $this->actingAs($actor)->put($url, putBody(['rank_id' => $newRank->id]))->assertRedirect($url);
    $e = PersonnelLogEntry::sole();
    expect($e->type)->toBe(PersonnelLogEntry::RANK_CHANGE)->and($e->content)->toBe("Dienstgrad wurde von {$this->rank->name} auf Neu geändert.")
        ->and($e->author)->toBe(ActorName::for($actor));

    PersonnelLogEntry::query()->delete();
    $this->actingAs($actor)->put($url, putBody(['rank_id' => $this->rank->id, 'phone' => '555', 'full_name' => 'Erika Neu']))->assertRedirect($url);
    expect(PersonnelLogEntry::orderBy('id')->pluck('type')->all())->toBe([PersonnelLogEntry::RANK_CHANGE, PersonnelLogEntry::MODIFICATION]);
    expect($this->person->fresh()->phone)->toBe('555');
});

it('logs a qualification change per area', function () {
    $fire = FireQualification::create(['priority' => 1, 'short_name' => 'GF', 'name' => 'Gruppenführer', 'name_male' => 'Gruppenführer', 'name_female' => 'Gruppenführerin']);
    $ems = EmsQualification::create(['priority' => 1, 'name' => 'NotSan', 'name_male' => 'NotSan', 'name_female' => 'NotSanin']);

    $this->actingAs(prUser('personnel.edit'))->put("/personnel/{$this->person->id}", putBody(['fire_qualification_id' => $fire->id, 'ems_qualification_id' => $ems->id]));

    $rows = PersonnelLogEntry::orderBy('id')->get();
    expect($rows)->toHaveCount(2)->and($rows->pluck('type')->unique()->all())->toBe([PersonnelLogEntry::MODIFICATION])
        ->and($rows->pluck('content')->implode('|'))->toContain('Qualifikation (FW) wurde von Keine auf Gruppenführer')->toContain('Qualifikation (RD)');
});

it('allows an archive rank when editing', function () {
    $archive = Rank::factory()->create(['is_archive' => true]);

    $this->actingAs(prUser('personnel.edit'))->put("/personnel/{$this->person->id}", putBody(['rank_id' => $archive->id]))->assertSessionHasNoErrors();

    expect($this->person->fresh()->rank_id)->toBe($archive->id);
});

it('allows the own service number but not a foreign one', function () {
    Personnel::factory()->create(['service_number' => 'BF-2', 'rank_id' => $this->rank->id]);
    $u = prUser('personnel.edit');

    $this->actingAs($u)->put("/personnel/{$this->person->id}", putBody())->assertSessionHasNoErrors();
    $this->actingAs($u)->put("/personnel/{$this->person->id}", putBody(['service_number' => 'BF-2']))->assertSessionHasErrors('service_number');
    $this->actingAs($u)->put("/personnel/{$this->person->id}", putBody(['service_number' => 'BF-3']))->assertSessionHasNoErrors();
    expect($this->person->fresh()->service_number)->toBe('BF-3');
});

it('validates the update body', function (array $bad, string $field) {
    $this->actingAs(prUser('personnel.edit'))->put("/personnel/{$this->person->id}", putBody($bad))->assertSessionHasErrors($field);
})->with([
    [['full_name' => ''], 'full_name'],
    [['discord_id' => '12345'], 'discord_id'],
    [['rank_id' => 9999], 'rank_id'],
    [['fire_qualification_id' => 9999], 'fire_qualification_id'],
    [['ems_qualification_id' => 9999], 'ems_qualification_id'],
    [['birth_date' => '31.12.1990'], 'birth_date'],
]);

it('rejects a discord id with a trailing newline', function () {
    $rule = (new StorePersonnelRequest)->rules()['discord_id'];

    expect(Validator::make(['discord_id' => "123456789012345678\n"], ['discord_id' => $rule])->fails())->toBeTrue()
        ->and(Validator::make(['discord_id' => '123456789012345678'], ['discord_id' => $rule])->fails())->toBeFalse();
});

it('ignores the author and the profile picture from the request', function () {
    $actor = prUser('personnel.edit');

    $this->actingAs($actor)->put("/personnel/{$this->person->id}", putBody(['rank_id' => Rank::factory()->create()->id, 'author' => 'Evil', 'profile_picture' => 'x.png']));
    $this->actingAs($actor)->post("/personnel/{$this->person->id}/notes", ['type' => 0, 'content' => 'hi', 'author' => 'Evil']);

    expect(PersonnelLogEntry::pluck('author')->unique()->all())->toBe([ActorName::for($actor)])
        ->and($this->person->fresh()->profile_picture)->toBeNull();
});

it('fires PersonnelSaved on every save', function () {
    Event::fake([PersonnelSaved::class]);
    $u = prUser('personnel.edit');

    $this->actingAs($u)->put("/personnel/{$this->person->id}", putBody());
    $this->actingAs($u)->put("/personnel/{$this->person->id}", putBody(['phone' => '1']));

    Event::assertDispatchedTimes(PersonnelSaved::class, 2);
});

it('sets and removes specialties and logs only real changes', function () {
    $a = Specialty::create(['number' => 1, 'name' => 'A', 'is_disabled' => false]);
    $b = Specialty::create(['number' => 2, 'name' => 'B', 'is_disabled' => false]);
    $u = prUser('personnel.edit');
    $url = "/personnel/{$this->person->id}/specialties";

    $this->actingAs($u)->put($url, ['specialties' => [$a->id, $b->id]])->assertRedirect("/personnel/{$this->person->id}");
    expect($this->person->specialties()->pluck('specialties.id')->all())->toEqualCanonicalizing([$a->id, $b->id])
        ->and(PersonnelLogEntry::where('content', 'Fachdienste wurden bearbeitet.')->count())->toBe(1);

    $this->actingAs($u)->put($url, ['specialties' => [$b->id, $a->id]]);
    expect(PersonnelLogEntry::count())->toBe(1);

    $this->actingAs($u)->put($url, ['specialties' => [$b->id]]);
    expect($this->person->specialties()->count())->toBe(1)->and(PersonnelLogEntry::count())->toBe(2);

    $this->actingAs($u)->put($url, [])->assertSessionHasNoErrors();
    expect($this->person->specialties()->count())->toBe(0);

    $this->actingAs($u)->put($url, ['specialties' => [9999]])->assertSessionHasErrors('specialties.0');
    $this->actingAs($u)->put($url, ['specialties' => 'x'])->assertSessionHasErrors('specialties');
});

it('adds notes of the three user types only', function () {
    $u = prUser('personnel.edit');
    $url = "/personnel/{$this->person->id}/notes";

    foreach ([0, 1, 2] as $type) {
        $this->actingAs($u)->post($url, ['type' => $type, 'content' => "T$type"])->assertRedirect("/personnel/{$this->person->id}");
    }
    expect(PersonnelLogEntry::orderBy('id')->pluck('type')->all())->toBe([0, 1, 2]);

    foreach ([['type' => 4, 'content' => 'x'], ['type' => 0, 'content' => ''], ['type' => 0, 'content' => str_repeat('a', 5001)], ['type' => 'a', 'content' => 'x']] as $bad) {
        $this->actingAs($u)->post($url, $bad)->assertSessionHasErrors();
    }
    expect(PersonnelLogEntry::count())->toBe(3);
});

it('deletes only user notes of that person and audits it', function () {
    $other = Personnel::factory()->create(['rank_id' => $this->rank->id]);
    $mine = note($this->person, 1);
    $system = note($this->person, 4);
    $foreign = note($other);
    $u = prUser('personnel.comment.delete');
    $base = "/personnel/{$this->person->id}/notes";

    $this->actingAs($u)->delete("$base/{$system->id}")->assertForbidden();
    $this->actingAs($u)->delete("$base/{$foreign->id}")->assertNotFound();
    expect(PersonnelLogEntry::count())->toBe(3);

    $this->actingAs($u)->delete("$base/{$mine->id}")->assertRedirect("/personnel/{$this->person->id}");
    expect(PersonnelLogEntry::find($mine->id))->toBeNull()->and(PersonnelLogEntry::count())->toBe(2);
    expect(Activity::where('description', 'Profil-Kommentar gelöscht [ID: '.$mine->id.']')->where('log_name', 'Mitarbeiter')->exists())->toBeTrue();
});

it('shares the own personnel record with the page', function () {
    $u = User::factory()->create(['personnel_id' => $this->person->id]);
    $this->actingAs($u)->get('/')->assertInertia(fn (Assert $p) => $p->where('auth.user.personnel', ['id' => $this->person->id, 'full_name' => 'Erika Muster']));

    $this->actingAs(User::factory()->create())->get('/')->assertInertia(fn (Assert $p) => $p->where('auth.user.personnel', null));
});

it('redirects the legacy profile URL by id', function (string $from, string $to) {
    $r = $this->get($from);
    $r->assertStatus(301);
    expect($r->headers->get('Location'))->toBe($to);
})->with([
    ['/personnel/profile?id=5', '/personnel/5'],
    ['/mitarbeiter/profile.php?id=12', '/personnel/12'],
    ['/personnel/profile?id=5&x=1', '/personnel/5'],
    ['/personnel/profile?id=abc', '/personnel'],
    ['/personnel/profile?id=-3', '/personnel'],
    ['/personnel/profile?id[]=3', '/personnel'],
    ['/personnel/profile', '/personnel'],
]);

it('answers 404 for an overflowing note id', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->delete("/personnel/{$this->person->id}/notes/".str_repeat('9', 25))->assertNotFound();
});

it('opens the documents tab only for viewers who may see documents', function () {
    $url = "/personnel/{$this->person->id}?tab=documents";
    $this->actingAs(prUser('personnel.view'))->get($url)->assertInertia(fn (Assert $p) => $p->where('tab', 'notes'));
    $this->actingAs(prUser('personnel.view', 'personnel.documents.view'))->get($url)->assertInertia(fn (Assert $p) => $p->where('tab', 'documents'));
    $this->actingAs(prUser('personnel.view'))->get("/personnel/{$this->person->id}?tab[]=documents")->assertInertia(fn (Assert $p) => $p->where('tab', 'notes'));
});
