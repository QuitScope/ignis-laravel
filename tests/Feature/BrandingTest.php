<?php

use App\Branding\LogoUpload;
use App\Branding\Theme;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const BR_OLD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png';
const BR_CUR = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.png';

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('branding');
});

function brUser(string ...$perms): User
{
    $role = Role::create(['name' => 'B'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function brSettings(): Settings
{
    return app(Settings::class);
}

/** @param 'png'|'jpeg'|'webp' $type */
function brImage(string $type = 'png', int $w = 40, int $h = 12, string $name = 'logo'): UploadedFile
{
    $img = imagecreatetruecolor($w, $h);
    ob_start();
    match ($type) {
        'png' => imagepng($img),
        'jpeg' => imagejpeg($img),
        'webp' => imagewebp($img),
    };

    return UploadedFile::fake()->createWithContent("{$name}.{$type}", (string) ob_get_clean());
}

function brContent(string $name, string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

function brPngBytes(): string
{
    return (string) brImage()->getContent();
}

function brCurrent(string $name = BR_CUR, string $body = 'png-bytes'): void
{
    Storage::disk('branding')->put($name, $body);
    brSettings()->set('system.logo', $name);
}

// ---------- upload ----------

it('stores a valid png, jpeg and webp under a random name, sets the setting and audits it', function (string $type, string $ext) {
    $this->actingAs(brUser('admin'))->post('/settings/system/logo', ['logo' => brImage($type)])
        ->assertRedirect('/settings/system/config?tab=general')->assertSessionHasNoErrors();

    $name = brSettings()->get('system.logo');
    expect($name)->toMatch('/^[a-f0-9]{32}\.'.$ext.'$/D');
    Storage::disk('branding')->assertExists($name);
    $entry = Activity::where('description', 'Logo geändert')->sole();
    expect($entry->log_name)->toBe('System')->and($entry->is_global)->toBeTruthy()->and($entry->details)->toBeNull();
})->with([['png', 'png'], ['jpeg', 'jpg'], ['webp', 'webp']]);

it('accepts a wide logo up to 4096 x 4096 pixels', function () {
    $this->actingAs(brUser('admin'))->post('/settings/system/logo', ['logo' => brImage('png', 2000, 100)])->assertSessionHasNoErrors();
    expect(brSettings()->get('system.logo'))->toMatch('/\.png$/');
});

it('refuses everything that is no small png, jpeg or webp, and stores nothing', function () {
    foreach ([
        'svg' => ['svg', fn () => brContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
        'html as png' => ['html', fn () => brContent('logo.png', '<html><script>alert(1)</script></html>')],
        'png magic only' => ['magic', fn () => brContent('logo.png', "\x89PNG\r\n\x1a\n<?php echo 1;")],
        'truncated' => ['truncated', fn () => brContent('logo.png', substr(brPngBytes(), 0, 40))],
        '3 MB' => ['big', fn () => brContent('logo.png', brPngBytes().str_repeat("\0", 3 * 1024 * 1024))],
        'pixel bomb' => ['bomb', function () {
            $png = brPngBytes();

            return brContent('logo.png', substr($png, 0, 16).pack('N2', 5000, 5000).substr($png, 24));
        }],
    ] as [$label, $file]) {
        $this->actingAs(brUser('admin'))->post('/settings/system/logo', ['logo' => $file()])->assertSessionHasErrors('logo');

        expect(brSettings()->get('system.logo'))->toBe('')
            ->and(Storage::disk('branding')->allFiles())->toBe([]);
    }
});

it('stores a decodable png with trailing php only as an inert .png (ImageUpload is no re-encoder)', function () {
    $this->actingAs(brUser('admin'))->post('/settings/system/logo', ['logo' => brContent('logo.php', brPngBytes()."\n<?php system(\$_GET['c']); ?>")])->assertSessionHasNoErrors();

    $name = brSettings()->get('system.logo');
    expect($name)->toMatch('/^[a-f0-9]{32}\.png$/D');
    expect($this->get('/branding/'.$name)->assertOk()->headers->get('Content-Type'))->toBe('image/png');
});

it('answers a missing file and an array of files with a translated error', function () {
    $admin = brUser('admin');
    $this->actingAs($admin)->post('/settings/system/logo', [])->assertSessionHasErrors(['logo' => trans('upload.missing')]);
    $this->actingAs($admin)->post('/settings/system/logo', ['logo' => [brImage()]])->assertSessionHasErrors(['logo' => trans('upload.missing')]);
    expect(Storage::disk('branding')->allFiles())->toBe([]);
});

it('names the reason for a pixel bomb and for a truncated image', function () {
    $admin = brUser('admin');
    $png = brPngBytes();

    $this->actingAs($admin)->post('/settings/system/logo', ['logo' => brContent('logo.png', substr($png, 0, 16).pack('N2', 5000, 5000).substr($png, 24))])
        ->assertSessionHasErrors(['logo' => trans('upload.too_many_pixels', ['side' => 4096])]);
    $this->actingAs($admin)->post('/settings/system/logo', ['logo' => brContent('logo.png', substr($png, 0, 40))])
        ->assertSessionHasErrors(['logo' => trans('upload.broken')]);
    expect(trans('upload.too_many_pixels', ['side' => 4096]))->toContain('zu viele Pixel');
});

it('lets a super admin upload and remove the logo', function () {
    $super = User::factory()->superAdmin()->create();

    $this->actingAs($super)->post('/settings/system/logo', ['logo' => brImage()])->assertSessionHasNoErrors();
    $name = brSettings()->get('system.logo');
    Storage::disk('branding')->assertExists($name);

    $this->actingAs($super)->delete('/settings/system/logo')->assertRedirect();
    expect(brSettings()->has('system.logo'))->toBeFalse()->and(Storage::disk('branding')->allFiles())->toBe([]);
});

it('limits upload and removal to the admin right', function () {
    $this->post('/settings/system/logo', ['logo' => brImage()])->assertRedirect('/login');
    $this->delete('/settings/system/logo')->assertRedirect('/login');
    foreach ([brUser('personnel.view'), User::factory()->create()] as $user) {
        $this->actingAs($user)->post('/settings/system/logo', ['logo' => brImage()])->assertForbidden();
        $this->actingAs($user)->delete('/settings/system/logo')->assertForbidden();
    }
    expect(Storage::disk('branding')->allFiles())->toBe([]);
});

it('deletes the old file only after the new one is saved, and keeps it when the save fails', function () {
    brCurrent(BR_OLD);
    $admin = brUser('admin');

    $this->actingAs($admin)->post('/settings/system/logo', ['logo' => brImage()])->assertSessionHasNoErrors();
    $new = brSettings()->get('system.logo');
    expect($new)->not->toBe(BR_OLD);
    Storage::disk('branding')->assertMissing(BR_OLD);
    Storage::disk('branding')->assertExists($new);

    $this->actingAs($admin)->post('/settings/system/logo', ['logo' => brContent('x.png', 'nope')])->assertSessionHasErrors('logo');
    expect(brSettings()->get('system.logo'))->toBe($new);
    Storage::disk('branding')->assertExists($new);
})->group('mariadb');

it('deletes the logo a concurrent upload saved, not the stale one this process still has cached', function () {
    brCurrent(BR_OLD);
    expect(app(LogoUpload::class)->current())->toBe(BR_OLD);
    Storage::disk('branding')->delete(BR_OLD);
    Storage::disk('branding')->put(BR_CUR, 'first-upload');
    Setting::where('key', 'system.logo')->update(['value' => json_encode(BR_CUR)]);

    $this->actingAs(brUser('admin'))->post('/settings/system/logo', ['logo' => brImage()])->assertSessionHasNoErrors();

    $new = brSettings()->get('system.logo');
    expect(Storage::disk('branding')->allFiles())->toBe([$new])->and($new)->not->toBe(BR_CUR);
})->group('mariadb');

it('removes a database failure\'s new file and keeps the old one', function () {
    brCurrent(BR_OLD);
    Setting::saving(fn () => throw new RuntimeException('db down'));

    $this->withoutExceptionHandling();
    expect(fn () => $this->actingAs(brUser('admin'))->post('/settings/system/logo', ['logo' => brImage()]))->toThrow(RuntimeException::class);

    expect(Storage::disk('branding')->allFiles())->toBe([BR_OLD]);
});

it('removes the logo: file gone, setting cleared, audited', function () {
    brCurrent();

    $this->actingAs(brUser('admin'))->delete('/settings/system/logo')->assertRedirect('/settings/system/config?tab=general');

    Storage::disk('branding')->assertMissing(BR_CUR);
    expect(brSettings()->has('system.logo'))->toBeFalse();
    expect(Activity::where('description', 'Logo entfernt')->sole()->details)->toBeNull();
    $this->actingAs(brUser('admin'))->delete('/settings/system/logo')->assertRedirect();
    expect(Activity::where('description', 'Logo entfernt')->count())->toBe(1);
});

it('never deletes outside the disk for a hostile stored value and shows no logo', function () {
    foreach (['../x.png', '../../branding-outside.png', 'C:\\Windows\\win.ini', '/etc/passwd', 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB.png', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.php', ''] as $value) {
        Storage::disk('branding')->put(BR_CUR, 'keep');
        $outside = storage_path('framework/testing/branding-outside.png');
        @mkdir(dirname($outside), 0777, true);
        file_put_contents($outside, 'keep');
        brSettings()->set('system.logo', $value);
        $admin = brUser('admin');

        $this->actingAs($admin)->get('/settings/system/config')->assertInertia(fn (Assert $p) => $p->where('branding.logo_url', null));
        $this->actingAs($admin)->delete('/settings/system/logo')->assertRedirect();
        $this->actingAs($admin)->post('/settings/system/logo', ['logo' => brImage()])->assertSessionHasNoErrors();

        expect(file_get_contents($outside))->toBe('keep');
        Storage::disk('branding')->assertExists(BR_CUR);
        @unlink($outside);
    }
});

// ---------- public route ----------

it('serves the current logo without login, session or cookie, with cache and nosniff headers', function () {
    brCurrent(BR_CUR, brPngBytes());

    $res = $this->get('/branding/'.BR_CUR)->assertOk();

    expect($res->headers->get('Content-Type'))->toBe('image/png')
        ->and($res->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($res->headers->get('Content-Disposition'))->toContain('inline')
        ->and($res->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=3600')
        ->and($res->headers->getCookies())->toBe([]);
    $this->head('/branding/'.BR_CUR)->assertOk();
});

it('serves jpeg and webp with their type', function (string $ext, string $mime) {
    $name = str_repeat('c', 32).'.'.$ext;
    brCurrent($name);

    expect($this->get('/branding/'.$name)->assertOk()->headers->get('Content-Type'))->toBe($mime);
})->with([['jpg', 'image/jpeg'], ['webp', 'image/webp']]);

it('answers 404 for an old, unknown or malformed name', function () {
    foreach ([
        'old name' => ['/branding/'.BR_OLD],
        'unknown' => ['/branding/dddddddddddddddddddddddddddddddd.png'],
        'encoded dots' => ['/branding/%2e%2e%2f%2e%2e%2f.env'],
        'encoded slash' => ['/branding/..%2f'.BR_CUR],
        'encoded backslash' => ['/branding/..%5c'.BR_CUR],
        'nul byte' => ['/branding/'.BR_CUR.'%00'],
        'newline' => ['/branding/'.BR_CUR.'%0A'],
        'php extension' => ['/branding/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.php'],
        'uppercase hex' => ['/branding/BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB.png'],
        'uppercase extension' => ['/branding/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.PNG'],
        '31 hex' => ['/branding/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.png'],
        '33 hex' => ['/branding/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.png'],
        'plain name' => ['/branding/secret.png'],
    ] as [$url]) {
        brCurrent();
        Storage::disk('branding')->put(BR_OLD, 'old');
        Storage::disk('branding')->put('secret.png', 'x');

        $this->get($url)->assertNotFound();
        $this->actingAs(brUser('admin'))->get($url)->assertNotFound();
    }
});

it('answers 404 when no logo is set, or the stored name is no valid name', function () {
    Storage::disk('branding')->put(BR_CUR, 'x');
    $this->get('/branding/'.BR_CUR)->assertNotFound();
    brSettings()->set('system.logo', '../'.BR_CUR);
    $this->get('/branding/'.BR_CUR)->assertNotFound();
});

it('throttles the public logo per address (429 after 120 a minute)', function () {
    brCurrent();
    foreach (range(1, 120) as $i) {
        $this->get('/branding/'.BR_CUR)->assertOk();
    }

    $res = $this->get('/branding/'.BR_CUR)->assertStatus(429);
    expect($res->headers->getCookies())->toBe([]);
});

// ---------- shared props ----------

it('shares the branding and legal props without secrets', function () {
    brCurrent();
    brSettings()->set('system.name', 'Leitstelle Nord');
    brSettings()->set('system.accent_color', '#2255aa');
    brSettings()->set('system.meta_image_url', 'https://example.org/og.png');
    brSettings()->set('legal.imprint_url', 'https://example.org/impressum');
    brSettings()->set('organisation.type', 'Feuerwehr');
    brSettings()->set('organisation.city', 'Musterstadt');
    brSettings()->set('api.key', 'SECRETKEY', encrypted: true);
    brSettings()->set('discord.client_secret', 'SECRETDISCORD', encrypted: true);

    $this->get('/login')->assertInertia(fn (Assert $p) => $p
        ->where('branding', [
            'name' => 'Leitstelle Nord',
            'logo_url' => '/branding/'.BR_CUR,
            'accent' => '#2255aa',
            'meta_image_url' => 'https://example.org/og.png',
            'tagline' => 'Verwaltungsportal der Feuerwehr Musterstadt',
            'org' => 'Feuerwehr Musterstadt',
        ])
        ->where('legal', ['imprint_url' => 'https://example.org/impressum', 'privacy_url' => null]));
    expect($this->get('/login')->getContent())->not->toContain('SECRETKEY')->not->toContain('SECRETDISCORD');
});

it('shows the footer tagline only when organisation type and city are both set', function () {
    foreach ([['Feuerwehr', 'Musterstadt', true], ['', 'Musterstadt', false], ['Feuerwehr', '', false], ['  ', ' ', false]] as [$type, $city, $shown]) {
        brSettings()->set('organisation.type', $type);
        brSettings()->set('organisation.city', $city);

        $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('branding.tagline', $shown ? "Verwaltungsportal der {$type} {$city}" : null));
    }
});

it('words the footer tagline in english when the installation language is english', function () {
    brSettings()->set('app.locale', 'en');
    brSettings()->set('organisation.type', 'Fire department');
    brSettings()->set('organisation.city', 'Musterstadt');

    $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('branding.tagline', 'Administration portal of the Fire department Musterstadt'));
});

it('ignores stored legal links and meta images that are not https', function () {
    foreach (['javascript:alert(1)', 'http://example.org/x', '//example.org/x', 'data:text/html,x', 'https:/x', 'ftp://example.org'] as $bad) {
        brSettings()->set('legal.imprint_url', $bad);
        brSettings()->set('legal.privacy_url', $bad);
        brSettings()->set('system.meta_image_url', $bad);

        $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('legal', ['imprint_url' => null, 'privacy_url' => null])->where('branding.meta_image_url', null));
        expect($this->get('/login')->getContent())->not->toContain('og:image');
    }
});

// ---------- accent colour, rendered ----------

it('applies a valid accent as core roles for both themes and as the old override for the tablet skin', function () {
    brSettings()->set('system.accent_color', '#2255AA');

    $html = $this->get('/login')->getContent();

    expect($html)->toContain('<style id="ignis-accent">:root:not([data-skin="classic"]){--accent:#2255aa;--accent-rgb:34, 85, 170;')
        ->toContain('--on-accent:#ffffff;--accent-fill:#2255aa;--accent-fill-2:#5e44a3;--accent-text:#7696ca;')
        ->toContain(':root:not([data-skin="classic"])[data-theme="light"]{--accent:#2255aa;')
        ->toContain(':root[data-skin="classic"],:root[data-skin="classic"][data-theme="light"]{--accent:#2255aa;--accent-hover:#1e4b96;--accent-rgb:34, 85, 170;--on-accent:#ffffff}');
    brSettings()->set('system.accent_color', '#ffd54a');
    expect($this->get('/login')->getContent())->toContain('--accent-rgb:255, 213, 74;--on-accent:#0b0b0b}');
});

it('darkens the hover of normal accents and lightens it for very dark ones', function () {
    foreach ([
        'mid blue darkens' => ['#2255aa', '#1e4b96'],
        'orange darkens' => ['#ffd54a', '#e0bb41'],
        'black lightens' => ['#000000', '#333333'],
        'near black lightens' => ['#111111', '#414141'],
    ] as [$accent, $hover]) {
        expect(Theme::css($accent))->toContain("--accent-hover:{$hover};");
    }
});

it('falls back to the tokens for hostile or garbage accents and never prints them', function () {
    foreach (['#12345', 'red', '#fff;}</style><script>alert(1)</script>', '#ggg000', "#2255aa\n", 'expression(1)', '#1234567'] as $bad) {
        brSettings()->set('system.accent_color', $bad);

        $res = $this->get('/login');
        $res->assertInertia(fn (Assert $p) => $p->where('branding.accent', null));
        $html = $res->getContent();

        expect($html)->toContain('{--accent:#f0500a;')->not->toContain('data-skin="classic"],')
            ->and($html)->not->toContain('}</style><script>')->not->toContain('red;')->not->toContain($bad === 'red' ? 'accent&quot;:&quot;red' : '#12345');
    }
});

it('leaves the stock orange to the tokens', function () {
    brSettings()->set('system.accent_color', '#F0500A');
    $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('branding.accent', null));
    expect(Theme::css(null))->toBe(Theme::css('#F0500A'))->toBe(Theme::css(['x']))
        ->toContain('--on-accent:#22110b;')->not->toContain('[data-skin="classic"],');
});

// ---------- head tags ----------

it('escapes the system name in title and og tags and writes og:image only for https', function () {
    brSettings()->set('system.name', 'A "quoted" <b>name</b> & co');
    brSettings()->set('system.meta_image_url', 'https://example.org/a.png?x=1&y="2"');

    $html = $this->get('/login')->getContent();

    expect($html)->toContain('<meta property="og:title" content="A &quot;quoted&quot; &lt;b&gt;name&lt;/b&gt; &amp; co">')
        ->toContain('<meta property="og:site_name" content="A &quot;quoted&quot; &lt;b&gt;name&lt;/b&gt; &amp; co">')
        ->toContain('<meta property="og:image" content="https://example.org/a.png?x=1&amp;y=&quot;2&quot;">')
        ->toContain('<meta property="og:description"')
        ->not->toContain('<b>name</b>');
});
