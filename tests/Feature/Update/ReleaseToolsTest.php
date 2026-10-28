<?php

use App\Update\ArchiveExtractor;
use App\Update\Manifest;
use App\Update\SignatureVerifier;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

uses()->group('slow');

/** @return array{int, string, string} exit code, stdout, stderr */
function releaseTool(string $tool, array $args): array
{
    $php = (new PhpExecutableFinder)->find() ?: 'php';
    $p = new Process([$php, base_path("tools/release/$tool.php"), ...$args]);
    $p->run();

    return [$p->getExitCode(), $p->getOutput(), $p->getErrorOutput()];
}

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/ignis-release-'.bin2hex(random_bytes(4));
    mkdir($this->tmp);
    $this->keyFile = "{$this->tmp}/update-private.key";
    [$code, $out] = releaseTool('keygen', [$this->keyFile]);
    expect($code)->toBe(0);
    preg_match('/^([A-Za-z0-9+\/=]{44})$/m', $out, $m);
    $this->public = $m[1];
    $this->secret = trim(file_get_contents($this->keyFile));

    $this->root = "{$this->tmp}/tree";
    $files = [
        'artisan' => '<?php', 'composer.json' => '{}', 'composer.lock' => '{}',
        'app/Foo.php' => '<?php', 'bootstrap/app.php' => '<?php', 'bootstrap/cache/packages.php' => 'x',
        'config/a.php' => '<?php', 'database/db.sqlite' => 'x', 'database/migrations/m.php' => '<?php',
        'lang/de.json' => '{}', 'public/index.php' => '<?php', 'public/build/app.js' => 'js', 'public/assets/map/satellite/0/0/0.jpg' => 'jpg', 'public/storage/up.txt' => 'x',
        'resources/update/public.key' => 'k', 'routes/web.php' => '<?php', 'vendor/autoload.php' => '<?php',
        'vendor/pkg/.gitattributes' => 'x',
        '.env.example' => 'APP_NAME=Ignis', 'database/x.sqlite-wal' => 'x', 'vendor/bin/tool' => '#!/bin/sh', 'vendor/composer/installed.json' => '{"dev":false,"packages":[]}',
        '.env' => 'SECRET', '.env.production' => 'SECRET', '.git/config' => 'x', 'node_modules/x/i.js' => 'x',
        'tests/FooTest.php' => 'x', 'storage/logs/l.log' => 'x', 'tools/release/sign.php' => 'x', 'docs/dev/a.md' => 'x', 'docs/screenshots/a.webp' => 'x',
    ];
    foreach ($files as $rel => $body) {
        @mkdir(dirname("{$this->root}/$rel"), 0777, true);
        file_put_contents("{$this->root}/$rel", $body);
    }
    $this->build = fn (string $v = '1.2.3') => releaseTool('build-archive', [$v, "--root={$this->root}", "--out={$this->tmp}", '--no-checks']);
});

afterEach(fn () => (new Filesystem)->deleteDirectory($this->tmp));

function zipNames(string $zip): array
{
    $z = new ZipArchive;
    $z->open($zip);
    $names = array_map(fn ($i) => $z->getNameIndex($i), range(0, $z->numFiles - 1));
    $z->close();

    return $names;
}

test('sign output verifies with SignatureVerifier', function () {
    ($this->build)();
    $zip = "{$this->tmp}/ignis-1.2.3.zip";
    [$code] = releaseTool('sign', [$zip, "--key={$this->keyFile}", '--image=ghcr.io/o/ignis', '--image-digest=sha256:'.str_repeat('a', 64)]);
    expect($code)->toBe(0);

    config(['ignis.update.public_key' => $this->public]);
    $json = file_get_contents("{$this->tmp}/manifest.json");
    expect(SignatureVerifier::verify($json, file_get_contents("{$this->tmp}/manifest.json.sig")))->toBeTrue()
        ->and(SignatureVerifier::verify($json.' ', file_get_contents("{$this->tmp}/manifest.json.sig")))->toBeFalse();
    $m = Manifest::parse($json);
    expect($m->version)->toBe('1.2.3')->and($m->image)->toBe('ghcr.io/o/ignis')->and($m->minVersion)->toBe('0.0.0');
});

test('manifest sha256 matches archive', function () {
    ($this->build)();
    $zip = "{$this->tmp}/ignis-1.2.3.zip";
    releaseTool('sign', [$zip, "--key={$this->keyFile}"]);
    $m = Manifest::parse(file_get_contents("{$this->tmp}/manifest.json"));
    expect($m->sha256)->toBe(hash_file('sha256', $zip))->and($m->archive)->toBe('ignis-1.2.3.zip');
});

test('archive excludes .env .git tests node_modules storage and contains VERSION, artisan, vendor marker', function () {
    [$code] = ($this->build)();
    expect($code)->toBe(0);
    $names = zipNames("{$this->tmp}/ignis-1.2.3.zip");
    foreach (['VERSION', 'artisan', 'vendor/autoload.php', 'public/build/app.js', 'resources/update/public.key', 'docs/dev/a.md'] as $in) {
        expect($names)->toContain("ignis-1.2.3/$in");
    }
    $bad = ['.env.production', '.env/', '.git', 'node_modules', 'tests/', 'storage/', 'tools/', 'docs/screenshots', '.sqlite', 'sqlite-wal', 'bootstrap/cache', 'public/storage', '.gitattributes'];
    foreach ($names as $n) {
        foreach ($bad as $b) {
            expect($n)->not->toContain($b);
        }
    }
    $sorted = array_slice($names, 1);
    sort($sorted, SORT_STRING);
    expect(array_slice($names, 1))->toBe($sorted);
});

test('archive includes the map tiles under public/assets/map', function () {
    [$code] = ($this->build)();
    expect($code)->toBe(0)->and(zipNames("{$this->tmp}/ignis-1.2.3.zip"))->toContain('ignis-1.2.3/public/assets/map/satellite/0/0/0.jpg');
});

test('archive includes modules/ when present (the tree in beforeEach has none and builds too)', function () {
    mkdir("{$this->root}/modules/Fleet", 0777, true);
    file_put_contents("{$this->root}/modules/Fleet/module.json", '{}');
    file_put_contents("{$this->root}/modules/.gitkeep", '');
    [$code] = ($this->build)();
    expect($code)->toBe(0)->and(zipNames("{$this->tmp}/ignis-1.2.3.zip"))->toContain('ignis-1.2.3/modules/Fleet/module.json');
    (new ArchiveExtractor)->extract("{$this->tmp}/ignis-1.2.3.zip", "{$this->tmp}/stage-m", '1.2.3');
    expect(is_file("{$this->tmp}/stage-m/modules/Fleet/module.json"))->toBeTrue();
});

test('archive built by the tool passes ArchiveExtractor and VERSION matches', function () {
    ($this->build)();
    $dest = "{$this->tmp}/staging";
    (new ArchiveExtractor)->extract("{$this->tmp}/ignis-1.2.3.zip", $dest, '1.2.3');
    expect(trim(file_get_contents("$dest/VERSION")))->toBe('1.2.3')
        ->and(is_file("$dest/artisan"))->toBeTrue()
        ->and(is_dir("$dest/bootstrap/cache"))->toBeTrue();
});

test('build-archive is deterministic and refuses bad versions and missing public/build', function () {
    ($this->build)();
    $first = hash_file('sha256', "{$this->tmp}/ignis-1.2.3.zip");
    ($this->build)();
    expect(hash_file('sha256', "{$this->tmp}/ignis-1.2.3.zip"))->toBe($first);

    expect(releaseTool('build-archive', ['v1.2.3', "--root={$this->root}", "--out={$this->tmp}", '--no-checks'])[0])->toBe(1);
    file_put_contents("{$this->root}/resources/update/public.key", $this->public);
    file_put_contents("{$this->root}/vendor/composer/installed.json", '{"dev":true}');
    [$code, , $err] = releaseTool('build-archive', ['1.2.3', "--root={$this->root}", "--out={$this->tmp}"]);
    expect($code)->toBe(1)->and($err)->toContain('--no-dev');
    (new Filesystem)->deleteDirectory("{$this->root}/public/build");
    [$code, , $err] = releaseTool('build-archive', ['1.2.3', "--root={$this->root}", "--out={$this->tmp}"]);
    expect($code)->toBe(1)->and($err)->toContain('public/build');
});

test('keygen refuses a path inside the repository and refuses overwrite', function () {
    $inside = base_path('storage/should-not-exist.key');
    [$code, , $err] = releaseTool('keygen', [$inside]);
    expect($code)->toBe(1)->and(file_exists($inside))->toBeFalse()->and($err)->toContain('repository');

    $before = file_get_contents($this->keyFile);
    [$code, , $err] = releaseTool('keygen', [$this->keyFile]);
    expect($code)->toBe(1)->and($err)->toContain('overwrite')->and(file_get_contents($this->keyFile))->toBe($before);
});

test('tools never print the private key', function () {
    $outputs = [];
    $outputs[] = releaseTool('keygen', ["{$this->tmp}/second.key"]);
    $secondSecret = trim(file_get_contents("{$this->tmp}/second.key"));
    $outputs[] = ($this->build)();
    $outputs[] = releaseTool('sign', ["{$this->tmp}/ignis-1.2.3.zip", "--key={$this->keyFile}"]);
    $outputs[] = releaseTool('sign', ["{$this->tmp}/ignis-1.2.3.zip", "--key={$this->keyFile}", '--version=bad']);
    $outputs[] = releaseTool('keygen', ["{$this->tmp}/second.key"]);
    foreach ($outputs as [, $out, $err]) {
        foreach ([$this->secret, $secondSecret, substr($this->secret, 0, 40)] as $s) {
            expect($out.$err)->not->toContain($s);
        }
    }
});

test('sign refuses invalid version, missing key and unreadable key file', function () {
    ($this->build)();
    $zip = "{$this->tmp}/ignis-1.2.3.zip";
    expect(releaseTool('sign', [$zip, "--key={$this->keyFile}", '--version=v1.2'])[0])->toBe(1)
        ->and(releaseTool('sign', [$zip, "--key={$this->keyFile}", '--min-version=x'])[0])->toBe(1)
        ->and(releaseTool('sign', [$zip])[0])->toBe(1)
        ->and(releaseTool('sign', [$zip, "--key={$this->tmp}/nope.key"])[0])->toBe(1)
        ->and(releaseTool('sign', [$zip, "--key={$this->tmp}"])[0])->toBe(1);
    file_put_contents("{$this->tmp}/garbage.key", 'not a key');
    expect(releaseTool('sign', [$zip, "--key={$this->tmp}/garbage.key"])[0])->toBe(1)
        ->and(releaseTool('sign', [$zip, "--key={$this->keyFile}", '--image=ghcr.io/o/x'])[0])->toBe(1)
        ->and(file_exists("{$this->tmp}/manifest.json"))->toBeFalse();
});

test('real public key is required unless --no-checks; first-install files and exec bits are in the archive', function () {
    $run = fn () => releaseTool('build-archive', ['1.2.3', "--root={$this->root}", "--out={$this->tmp}"]);
    file_put_contents("{$this->root}/resources/update/public.key", 'PLACEHOLDER');
    [$code, , $err] = $run();
    expect($code)->toBe(1)->and($err)->toContain('public.key')->and(file_exists("{$this->tmp}/ignis-1.2.3.zip"))->toBeFalse();

    file_put_contents("{$this->root}/resources/update/public.key", $this->public.'
');
    expect($run()[0])->toBe(0);

    $zip = new ZipArchive;
    $zip->open("{$this->tmp}/ignis-1.2.3.zip");
    foreach (['.env.example', 'artisan', 'composer.json', 'public/index.php', 'bootstrap/app.php', 'VERSION'] as $f) {
        expect($zip->locateName("ignis-1.2.3/$f"))->not->toBeFalse();
    }
    $zip->getExternalAttributesName('ignis-1.2.3/vendor/bin/tool', $os, $attr);
    expect(($attr >> 16) & 0777)->toBe(0755);
    $zip->close();

    (new ArchiveExtractor)->extract("{$this->tmp}/ignis-1.2.3.zip", "{$this->tmp}/stage2", '1.2.3');
    expect(file_exists("{$this->tmp}/stage2/.env.example"))->toBeFalse();
});
