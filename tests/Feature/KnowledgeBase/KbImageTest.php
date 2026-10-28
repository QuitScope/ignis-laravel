<?php

use App\Models\Role;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Modules\KnowledgeBase\Content\KbContentValidator;

uses(RefreshDatabase::class);

const KBI_NAME = '0123456789abcdef0123456789abcdef.png';

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('kb-images');
});

function kbi_user(string ...$perms): User
{
    $role = Role::create(['name' => 'I'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

/** @param 'png'|'jpeg'|'webp'|'gif' $type */
function kbi_bytes(string $type = 'png', int $w = 20, int $h = 10): string
{
    $img = imagecreatetruecolor($w, $h);
    ob_start();
    match ($type) {
        'png' => imagepng($img),
        'jpeg' => imagejpeg($img),
        'webp' => imagewebp($img),
        'gif' => imagegif($img),
    };

    return (string) ob_get_clean();
}

function kbi_file(string $name, string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

function kbi_public(bool $on): void
{
    app(Settings::class)->set('knowledgebase.public_access', $on);
}

// ---------- upload ----------

it('stores each allowed format under a random name and answers {src, alt} with a src the validator accepts', function (string $type, string $ext) {
    $res = $this->actingAs(kbi_user('kb.edit'))->postJson('/lexicon/images', ['image' => kbi_file('bild.'.$type, kbi_bytes($type))])->assertCreated();

    $src = $res->json('src');
    expect($src)->toMatch('~^/storage/kb-images/[a-f0-9]{32}\.'.$ext.'$~D')
        ->and($res->json('alt'))->toBe('')
        ->and(array_keys($res->json()))->toBe(['src', 'alt'])
        ->and(KbContentValidator::validate(['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => $src]]]]))->toHaveKey('content.0.attrs.src', $src);
    Storage::disk('kb-images')->assertExists(basename($src));
    expect($this->get($src)->assertOk()->headers->get('Content-Type'))->toBe(['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'][$ext]);
})->with([['png', 'png'], ['jpeg', 'jpg'], ['webp', 'webp'], ['gif', 'gif']]);

it('names the file from the detected type, never from the client', function () {
    $res = $this->actingAs(kbi_user('kb.edit'))->postJson('/lexicon/images', ['image' => kbi_file('x.php', kbi_bytes('jpeg'))])->assertCreated();

    expect($res->json('src'))->toEndWith('.jpg');
});

it('refuses everything that is no small jpg, png, webp or gif, and stores nothing', function () {
    foreach ([
        'svg' => [fn () => kbi_file('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'Ungültiger Dateityp'],
        'svg named png' => [fn () => kbi_file('a.png', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'), 'Ungültiger Dateityp'],
        'html named png' => [fn () => kbi_file('a.png', '<html><script>alert(1)</script></html>'), 'Ungültiger Dateityp'],
        'text' => [fn () => kbi_file('a.txt', 'kein Bild'), 'Ungültiger Dateityp'],
        'pdf' => [fn () => kbi_file('a.png', "%PDF-1.4\n1 0 obj\n"), 'Ungültiger Dateityp'],
        'polyglot: png magic + php' => [fn () => kbi_file('a.png', "\x89PNG\r\n\x1a\n<?php echo 1;"), null],
        'polyglot: gif header + html' => [fn () => kbi_file('a.gif', 'GIF89a<html><script>alert(1)</script>'), null],
        'truncated png' => [fn () => kbi_file('a.png', substr(kbi_bytes(), 0, 40)), 'konnte nicht gelesen'],
        'over 5 MB' => [fn () => kbi_file('a.png', kbi_bytes().str_repeat("\0", 5 * 1024 * 1024)), 'zu groß'],
        'pixel bomb' => [function () {
            $png = kbi_bytes();

            return kbi_file('a.png', substr($png, 0, 16).pack('N2', 5000, 5000).substr($png, 24));
        }, 'zu viele Pixel'],
    ] as [$file, $reason]) {
        $this->actingAs(kbi_user('kb.edit'))->postJson('/lexicon/images', ['image' => $file()])
            ->assertUnprocessable()->assertJsonValidationErrors($reason === null ? ['image'] : ['image' => $reason]);

        expect(Storage::disk('kb-images')->allFiles())->toBe([]);
    }
});

it('answers a missing file or an array of files with a translated error', function () {
    $user = kbi_user('kb.edit');
    $this->actingAs($user)->postJson('/lexicon/images', [])->assertJsonValidationErrors(['image' => trans('upload.missing')]);
    $this->actingAs($user)->postJson('/lexicon/images', ['image' => [kbi_file('a.png', kbi_bytes())]])->assertJsonValidationErrors(['image' => trans('upload.missing')]);
    $this->actingAs($user)->postJson('/lexicon/images', ['image' => 'data:image/png;base64,AAAA'])->assertJsonValidationErrors(['image' => trans('upload.missing')]);
    expect(Storage::disk('kb-images')->allFiles())->toBe([]);
});

it('lets kb.edit, admin and super admin upload; refuses other accounts with 403 and guests with 401/login', function () {
    foreach ([kbi_user('kb.edit'), kbi_user('admin'), User::factory()->superAdmin()->create()] as $user) {
        $this->actingAs($user)->postJson('/lexicon/images', ['image' => kbi_file('a.png', kbi_bytes())])->assertCreated();
    }
    expect(Storage::disk('kb-images')->allFiles())->toHaveCount(3);

    foreach ([kbi_user('kb.archive'), kbi_user('audit.view')] as $user) {
        $this->actingAs($user)->postJson('/lexicon/images', ['image' => kbi_file('a.png', kbi_bytes())])->assertForbidden();
    }
    auth()->logout();
    $this->postJson('/lexicon/images', ['image' => kbi_file('a.png', kbi_bytes())])->assertUnauthorized();
    $this->post('/lexicon/images', ['image' => kbi_file('a.png', kbi_bytes())])->assertRedirect('/login');
    kbi_public(true);
    $this->post('/lexicon/images', ['image' => kbi_file('a.png', kbi_bytes())])->assertRedirect('/login');
    expect(Storage::disk('kb-images')->allFiles())->toHaveCount(3);
});

it('throttles uploads per account (named bucket)', function () {
    $user = kbi_user('kb.edit');
    foreach (range(1, 30) as $i) {
        $this->actingAs($user)->postJson('/lexicon/images', [])->assertUnprocessable();
    }
    $this->actingAs($user)->postJson('/lexicon/images', [])->assertStatus(429);
});

// ---------- delivery ----------

it('serves an image to signed-in accounts privately with nosniff while the switch is off', function () {
    Storage::disk('kb-images')->put(KBI_NAME, kbi_bytes());

    $res = $this->actingAs(kbi_user('audit.view'))->get('/storage/kb-images/'.KBI_NAME)->assertOk();

    expect($res->headers->get('Content-Type'))->toBe('image/png')
        ->and($res->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($res->headers->get('Content-Disposition'))->toContain('inline')
        ->and($res->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=3600')->not->toContain('public');
});

it('sends guests to the login while the switch is off (JSON: 401), without telling whether the file exists', function () {
    Storage::disk('kb-images')->put(KBI_NAME, kbi_bytes());

    $this->get('/storage/kb-images/'.KBI_NAME)->assertRedirect('/login');
    $this->get('/storage/kb-images/ffffffffffffffffffffffffffffffff.png')->assertRedirect('/login');
    $this->getJson('/storage/kb-images/'.KBI_NAME)->assertUnauthorized();
});

it('signs out a deactivated account instead of serving', function () {
    Storage::disk('kb-images')->put(KBI_NAME, kbi_bytes());
    $user = kbi_user('kb.edit');
    $user->forceFill(['is_active' => false])->save();

    $this->actingAs($user)->get('/storage/kb-images/'.KBI_NAME)->assertRedirect();
    expect(auth()->check())->toBeFalse();
});

it('serves guests publicly, without session cookie, while the switch is on', function () {
    Storage::disk('kb-images')->put(KBI_NAME, kbi_bytes());
    kbi_public(true);

    $res = $this->get('/storage/kb-images/'.KBI_NAME)->assertOk();
    expect($res->headers->get('Content-Type'))->toBe('image/png')
        ->and($res->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($res->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=3600')
        ->and($res->headers->getCookies())->toBe([]);

    $res = $this->actingAs(kbi_user('audit.view'))->get('/storage/kb-images/'.KBI_NAME)->assertOk();
    expect($res->headers->getCookies())->toBe([])->and($res->headers->get('Cache-Control'))->toContain('public');
});

it('serves the type of each extension', function (string $ext, string $mime) {
    $name = str_repeat('a', 32).'.'.$ext;
    Storage::disk('kb-images')->put($name, 'x');

    expect($this->actingAs(kbi_user('audit.view'))->get('/storage/kb-images/'.$name)->assertOk()->headers->get('Content-Type'))->toBe($mime);
})->with([['png', 'image/png'], ['jpg', 'image/jpeg'], ['webp', 'image/webp'], ['gif', 'image/gif']]);

it('answers 404 for unknown, malformed and traversal names, signed in or public', function () {
    foreach ([
        'unknown name' => ['/storage/kb-images/ffffffffffffffffffffffffffffffff.png'],
        'plain name' => ['/storage/kb-images/secret.png'],
        'svg' => ['/storage/kb-images/'.str_repeat('a', 32).'.svg'],
        'html' => ['/storage/kb-images/'.str_repeat('a', 32).'.html'],
        'php extension' => ['/storage/kb-images/0123456789abcdef0123456789abcdef.php'],
        'jpeg spelled out' => ['/storage/kb-images/0123456789abcdef0123456789abcdef.jpeg'],
        'uppercase extension' => ['/storage/kb-images/0123456789abcdef0123456789abcdef.PNG'],
        'uppercase hex' => ['/storage/kb-images/0123456789ABCDEF0123456789abcdef.png'],
        'double extension' => ['/storage/kb-images/0123456789abcdef0123456789abcdef.png.png'],
        '31 hex' => ['/storage/kb-images/0123456789abcdef0123456789abcde.png'],
        '33 hex' => ['/storage/kb-images/0123456789abcdef0123456789abcdef0.png'],
        'encoded dots' => ['/storage/kb-images/%2e%2e%2f%2e%2e%2f.env'],
        'encoded slash' => ['/storage/kb-images/..%2f'.KBI_NAME],
        'encoded backslash' => ['/storage/kb-images/..%5c'.KBI_NAME],
        'dot segments' => ['/storage/kb-images/../profile-pictures/'.KBI_NAME],
        'nul byte' => ['/storage/kb-images/'.KBI_NAME.'%00'],
        'newline' => ['/storage/kb-images/'.KBI_NAME.'%0A'],
        'trailing dot' => ['/storage/kb-images/'.KBI_NAME.'.'],
        'empty' => ['/storage/kb-images/'],
    ] as [$url]) {
        Storage::disk('kb-images')->put(KBI_NAME, 'x');
        Storage::disk('kb-images')->put('secret.png', 'x');
        Storage::disk('kb-images')->put(str_repeat('a', 32).'.svg', '<svg/>');
        Storage::disk('kb-images')->put(str_repeat('a', 32).'.html', '<script>');

        $this->actingAs(kbi_user('admin'))->get($url)->assertNotFound();
        auth()->logout();
        kbi_public(true);
        $this->get($url)->assertNotFound();
    }
});

it('is not served by the generic /storage/{area}/{file} route for other disks, and that route does not serve kb-images', function () {
    Storage::fake('profile-pictures');
    Storage::disk('profile-pictures')->put(KBI_NAME, 'x');
    kbi_public(true);

    $this->get('/storage/kb-images/'.KBI_NAME)->assertNotFound();
    $this->actingAs(kbi_user('audit.view'))->get('/storage/profile-pictures/'.KBI_NAME)->assertOk();
});

it('answers 404 for everything while the module is off, also with the public switch on', function () {
    Storage::disk('kb-images')->put(KBI_NAME, kbi_bytes());
    kbi_public(true);
    app(Settings::class)->set('modules.knowledgebase.enabled', false);

    $this->get('/storage/kb-images/'.KBI_NAME)->assertNotFound();
    $this->actingAs(kbi_user('admin'))->get('/storage/kb-images/'.KBI_NAME)->assertNotFound();
    $this->actingAs(kbi_user('admin'))->postJson('/lexicon/images', ['image' => kbi_file('a.png', kbi_bytes())])->assertNotFound();
    expect(Storage::disk('kb-images')->allFiles())->toBe([KBI_NAME]);
});

it('throttles the delivery per address (named bucket)', function () {
    Storage::disk('kb-images')->put(KBI_NAME, kbi_bytes());
    kbi_public(true);
    foreach (range(1, 240) as $i) {
        $this->get('/storage/kb-images/'.KBI_NAME)->assertOk();
    }
    $this->get('/storage/kb-images/'.KBI_NAME)->assertStatus(429);
});

it('the core /storage/{area}/{file} route never matches the kb-images area, whatever the registration order', function () {
    $core = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'storage/{area}/{file}');

    expect($core)->not->toBeNull()
        ->and($core->matches(Request::create('/storage/kb-images/'.KBI_NAME)))->toBeFalse()
        ->and($core->matches(Request::create('/storage/kb-images/x')))->toBeFalse()
        ->and($core->matches(Request::create('/storage/profile-pictures/'.KBI_NAME)))->toBeTrue()
        ->and($core->matches(Request::create('/storage/kb-imagesx/'.KBI_NAME)))->toBeTrue()
        ->and($core->matches(Request::create('/storage/unknown/x.pdf')))->toBeTrue();
});

it('keeps the image route and the core storage route apart in the compiled (route:cache) matcher', function () {
    $dump = app('router')->getRoutes()->compile();
    $routes = (new CompiledRouteCollection($dump['compiled'], $dump['attributes']))->setRouter(app('router'))->setContainer(app());
    $match = fn (string $path) => $routes->match(Request::create($path))->uri();

    expect($match('/storage/kb-images/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png'))->toBe('storage/kb-images/{file}')
        ->and($match('/storage/other/x.png'))->toBe('storage/{area}/{file}');

    $kb = collect($routes->getRoutes())->first(fn ($r) => $r->uri() === 'storage/kb-images/{file}');
    expect(app('router')->resolveMiddleware($kb->gatherMiddleware(), $kb->excludedMiddleware()))->toContain('module:knowledgebase')
        ->and($kb->excludedMiddleware())->toContain(StartSession::class);
});
