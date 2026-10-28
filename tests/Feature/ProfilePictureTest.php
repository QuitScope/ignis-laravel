<?php

use App\Events\PersonnelSaved;
use App\Models\Personnel;
use App\Models\PersonnelLogEntry;
use App\Models\Rank;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('profile-pictures');
    $this->person = Personnel::factory()->create(['rank_id' => Rank::factory()->create()->id, 'full_name' => 'Erika Muster']);
    $this->editor = ppUser('personnel.view', 'personnel.edit');
});

function ppUser(string ...$perms): User
{
    $role = Role::create(['name' => 'R'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function ppPng(): UploadedFile
{
    $img = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($img);

    return UploadedFile::fake()->createWithContent('me.png', (string) ob_get_clean());
}

function ppUrl(): string
{
    return '/personnel/'.test()->person->id.'/picture';
}

it('stores the upload under a random name, logs it and fires PersonnelSaved', function () {
    Event::fake([PersonnelSaved::class]);

    $this->actingAs($this->editor)->post(ppUrl(), ['picture' => ppPng()])->assertRedirect('/personnel/'.$this->person->id);

    $name = $this->person->fresh()->profile_picture;
    expect($name)->toMatch('/^[a-f0-9]{32}\.png$/D');
    Storage::disk('profile-pictures')->assertExists($name);
    $entry = PersonnelLogEntry::where('personnel_id', $this->person->id)->sole();
    expect($entry->type)->toBe(PersonnelLogEntry::MODIFICATION)
        ->and($entry->content)->toBe('Profilbild geändert.')
        ->and($entry->metadata)->toBe(['change_type' => 'profile_picture']);
    Event::assertDispatched(PersonnelSaved::class, fn ($e) => $e->personnelId === $this->person->id);
});

it('ignores client-supplied names and paths', function () {
    $this->actingAs($this->editor)->post(ppUrl(), ['picture' => ppPng(), 'file_name' => '../../.env', 'path' => '/etc', 'profile_picture' => 'x.png']);

    $name = $this->person->fresh()->profile_picture;
    expect($name)->toMatch('/^[a-f0-9]{32}\.png$/D');
    expect(Storage::disk('profile-pictures')->allFiles())->toBe([$name]);
});

it('replaces the old picture and deletes its file', function () {
    $this->actingAs($this->editor)->post(ppUrl(), ['picture' => ppPng()]);
    $old = $this->person->fresh()->profile_picture;

    $this->actingAs($this->editor)->post(ppUrl(), ['picture' => ppPng()]);
    $new = $this->person->fresh()->profile_picture;

    expect($new)->not->toBe($old);
    expect(Storage::disk('profile-pictures')->allFiles())->toBe([$new]);
});

it('never deletes a stored value that is not a plain generated file name', function (string $stored) {
    Storage::disk('profile-pictures')->put('keep.png', 'x');
    Storage::disk('profile-pictures')->put('sub/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png', 'x');
    $this->person->update(['profile_picture' => $stored]);

    $this->actingAs($this->editor)->post(ppUrl(), ['picture' => ppPng()]);
    $this->actingAs($this->editor)->delete(ppUrl());

    Storage::disk('profile-pictures')->assertExists(['keep.png', 'sub/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png']);
})->with(['keep.png', 'sub/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png', '../profile-pictures/keep.png', '/storage/profile-pictures/keep.png']);

it('rejects bad uploads with a validation error and stores nothing', function (array $body, string $message) {
    $this->actingAs($this->editor)->from('/personnel/'.$this->person->id)->post(ppUrl(), $body)
        ->assertRedirect('/personnel/'.$this->person->id)->assertSessionHasErrors(['picture' => $message]);

    expect($this->person->fresh()->profile_picture)->toBeNull();
    expect(Storage::disk('profile-pictures')->allFiles())->toBe([]);
    expect(PersonnelLogEntry::count())->toBe(0);
})->with([
    'svg' => fn () => [['picture' => UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')], 'Ungültiger Dateityp. Erlaubt: png, jpg, webp'],
    'html as png' => fn () => [['picture' => UploadedFile::fake()->createWithContent('a.png', '<html><script>alert(1)</script></html>')], 'Ungültiger Dateityp. Erlaubt: png, jpg, webp'],
    '3 MB' => fn () => [['picture' => UploadedFile::fake()->createWithContent('a.png', str_repeat('x', 3 * 1024 * 1024))], 'Datei ist zu groß (max. 2 MB)'],
    'no file' => fn () => [[], 'Bitte ein Bild auswählen.'],
    'string instead of file' => fn () => [['picture' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png'], 'Bitte ein Bild auswählen.'],
    'array of files' => fn () => [['picture' => [ppPng()]], 'Bitte ein Bild auswählen.'],
]);

it('leaves no orphan file when the database write fails', function () {
    Personnel::saving(fn () => throw new RuntimeException('db down'));

    $this->withoutExceptionHandling();
    expect(fn () => $this->actingAs($this->editor)->post(ppUrl(), ['picture' => ppPng()]))->toThrow(RuntimeException::class, 'db down');

    expect(Storage::disk('profile-pictures')->allFiles())->toBe([]);
    expect(PersonnelLogEntry::count())->toBe(0);
});

it('removes the picture, its file, and logs it', function () {
    $this->actingAs($this->editor)->post(ppUrl(), ['picture' => ppPng()]);
    Event::fake([PersonnelSaved::class]);

    $this->actingAs($this->editor)->delete(ppUrl())->assertRedirect('/personnel/'.$this->person->id);

    expect($this->person->fresh()->profile_picture)->toBeNull();
    expect(Storage::disk('profile-pictures')->allFiles())->toBe([]);
    expect(PersonnelLogEntry::latest('id')->first()->content)->toBe('Profilbild entfernt.');
    Event::assertDispatched(PersonnelSaved::class);
});

it('does nothing when removing a picture that is not there', function () {
    $this->actingAs($this->editor)->delete(ppUrl())->assertRedirect();

    expect(PersonnelLogEntry::count())->toBe(0);
});

it('needs the update ability for upload and removal', function () {
    $viewer = ppUser('personnel.view');

    $this->post(ppUrl(), ['picture' => ppPng()])->assertRedirect('/login');
    $this->delete(ppUrl())->assertRedirect('/login');
    $this->actingAs($viewer)->post(ppUrl(), ['picture' => ppPng()])->assertForbidden();
    $this->actingAs($viewer)->delete(ppUrl())->assertForbidden();
    $this->actingAs(ppUser('personnel.edit'))->post(ppUrl(), ['picture' => ppPng()])->assertRedirect();

    expect(Storage::disk('profile-pictures')->allFiles())->toHaveCount(1);
});

it('serves a stored picture to signed-in users with safe headers', function () {
    $name = str_repeat('ab', 16).'.webp';
    Storage::disk('profile-pictures')->put($name, 'not even a webp');

    $response = $this->actingAs(ppUser())->get("/storage/profile-pictures/{$name}")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/webp')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Disposition'))->toBe('inline')
        ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=3600')->not->toContain('public');
    expect($response->streamedContent())->toBe('not even a webp');
});

it('redirects guests to the login page', function () {
    $name = str_repeat('ab', 16).'.png';
    Storage::disk('profile-pictures')->put($name, 'x');

    $this->get("/storage/profile-pictures/{$name}")->assertRedirect('/login');
});

it('answers 404 for anything but a plain generated name in a known area', function () {
    foreach ([
        'dot dot' => '/storage/profile-pictures/../../.env',
        'encoded dot dot' => '/storage/profile-pictures/%2e%2e/%2e%2e/.env',
        'encoded slash' => '/storage/profile-pictures/..%2f..%2f.env',
        'double encoded' => '/storage/profile-pictures/%252e%252e%252f.env',
        'backslash' => '/storage/profile-pictures/..%5c..%5c.env',
        'slash in name' => '/storage/profile-pictures/sub/'.str_repeat('ab', 16).'.png',
        'wrong extension' => '/storage/profile-pictures/'.str_repeat('ab', 16).'.gif',
        'php extension' => '/storage/profile-pictures/'.str_repeat('ab', 16).'.php',
        'upper case' => '/storage/profile-pictures/'.str_repeat('AB', 16).'.png',
        'trailing newline' => '/storage/profile-pictures/'.str_repeat('ab', 16).'.png%0a',
        'null byte' => '/storage/profile-pictures/'.str_repeat('ab', 16).'.png%00.png',
        'not generated' => '/storage/profile-pictures/readme.png',
        'missing file' => '/storage/profile-pictures/'.str_repeat('cd', 16).'.png',
        'unknown area' => '/storage/documents/'.str_repeat('ab', 16).'.png',
        'area traversal' => '/storage/..%2fprofile-pictures/'.str_repeat('ab', 16).'.png',
    ] as $path) {
        Storage::disk('profile-pictures')->put(str_repeat('ab', 16).'.png', 'x');
        Storage::disk('profile-pictures')->put(str_repeat('ab', 16).'.gif', 'x');
        Storage::disk('profile-pictures')->put('readme.png', 'x');

        $this->actingAs(ppUser())->get($path)->assertNotFound();
    }
});

it('does not expose the framework storage route of the local disk', function () {
    expect(Route::has('storage.local'))->toBeFalse();
});

it('passes the picture URL to the profile page', function () {
    $this->actingAs($this->editor)->post(ppUrl(), ['picture' => ppPng()]);
    $name = $this->person->fresh()->profile_picture;

    $this->actingAs($this->editor)->get('/personnel/'.$this->person->id)
        ->assertInertia(fn (Assert $page) => $page->where('person.picture_url', "/storage/profile-pictures/{$name}"));

    $this->person->update(['profile_picture' => '/storage/profile-pictures/x.png']);
    $this->actingAs($this->editor)->get('/personnel/'.$this->person->id)
        ->assertInertia(fn (Assert $page) => $page->where('person.picture_url', null));
});
