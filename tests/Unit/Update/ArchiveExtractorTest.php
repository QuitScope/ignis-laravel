<?php

use App\Update\ArchiveExtractor;
use App\Update\UpdateException;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class);

/** A minimal valid release tree (flat). @return array<string, string> */
function aeRelease(string $version = '2.0.0', string $prefix = ''): array
{
    return [
        "{$prefix}VERSION" => "$version\n",
        "{$prefix}artisan" => '<?php echo "artisan";',
        "{$prefix}vendor/autoload.php" => '<?php',
        "{$prefix}public/build/manifest.json" => '{}',
        "{$prefix}app/Models/User.php" => '<?php // user',
    ];
}

/**
 * Writes a zip; $symlinks are entries marked as Unix symlinks.
 *
 * @param  array<string, string>  $files
 * @param  list<string>  $symlinks
 */
function aeZip(array $files, array $symlinks = []): string
{
    $path = sys_get_temp_dir().'/ignis-ae-'.bin2hex(random_bytes(4)).'.zip';
    register_shutdown_function(fn () => @unlink($path));
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
        if (in_array($name, $symlinks, true)) {
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (0120777) << 16);
        }
    }
    $zip->close();

    return $path;
}

function aeDest(): string
{
    $dir = sys_get_temp_dir().'/ignis-ae-dest-'.bin2hex(random_bytes(4));
    register_shutdown_function(fn () => (new Filesystem)->deleteDirectory($dir));

    return $dir;
}

function aeRefuses(string $zip, string $dest, ?ArchiveExtractor $x = null, string $version = '2.0.0'): void
{
    expect(fn () => ($x ?? new ArchiveExtractor)->extract($zip, $dest, $version))
        ->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('archive_invalid'));
    expect(file_exists($dest))->toBeFalse();
}

it('extracts a flat release and creates bootstrap/cache', function () {
    $dest = aeDest();
    (new ArchiveExtractor)->extract(aeZip(aeRelease()), $dest, '2.0.0');

    expect(file_get_contents("$dest/app/Models/User.php"))->toBe('<?php // user')
        ->and(is_dir("$dest/bootstrap/cache"))->toBeTrue();
});

it('strips the ignis-<version>/ root directory', function () {
    $dest = aeDest();
    (new ArchiveExtractor)->extract(aeZip(aeRelease('2.0.0', 'ignis-2.0.0/')), $dest, '2.0.0');

    expect(trim((string) file_get_contents("$dest/VERSION")))->toBe('2.0.0')->and(is_dir("$dest/ignis-2.0.0"))->toBeFalse();
});

it('never extracts .env, storage or .git from an archive', function () {
    $dest = aeDest();
    aeRefuses(aeZip(aeRelease() + ['.env' => 'APP_KEY=evil']), $dest);
    aeRefuses(aeZip(aeRelease() + ['.git/config' => 'x']), $dest);
    (new ArchiveExtractor)->extract(aeZip(aeRelease() + ['storage/app/x' => 'x', 'README.md' => 'r']), $dest, '2.0.0');
    expect(file_exists("$dest/storage"))->toBeFalse()->and(file_exists("$dest/README.md"))->toBeFalse();
});

it('extracts modules/ and works without it', function () {
    $dest = aeDest();
    (new ArchiveExtractor)->extract(aeZip(aeRelease() + ['modules/Fleet/module.json' => '{}']), $dest, '2.0.0');
    expect(file_get_contents("$dest/modules/Fleet/module.json"))->toBe('{}');
    $dest = aeDest();
    (new ArchiveExtractor)->extract(aeZip(aeRelease()), $dest, '2.0.0');
    expect(file_exists("$dest/modules"))->toBeFalse();
});

it('rejects traversal, absolute path, symlink, too many entries, oversize, wrong VERSION', function () {
    $dest = aeDest();
    aeRefuses(aeZip(aeRelease() + ['app/../../evil.php' => 'x']), $dest);
    aeRefuses(aeZip(aeRelease() + ['../evil.php' => 'x']), $dest);
    aeRefuses(aeZip(aeRelease() + ['/etc/evil' => 'x']), $dest);
    aeRefuses(aeZip(aeRelease() + ['C:/evil' => 'x']), $dest);
    aeRefuses(aeZip(aeRelease() + ['app\\..\\..\\evil' => 'x']), $dest);
    aeRefuses(aeZip(aeRelease() + ['app/link' => '/etc/passwd'], ['app/link']), $dest);
    aeRefuses(aeZip(aeRelease()), $dest, new ArchiveExtractor(maxEntries: 3));
    aeRefuses(aeZip(aeRelease() + ['app/big.bin' => str_repeat('a', 5000)]), $dest, new ArchiveExtractor(maxFileBytes: 4096));
    aeRefuses(aeZip(aeRelease() + ['app/a.bin' => str_repeat('a', 3000), 'app/b.bin' => str_repeat('b', 3000)]), $dest, new ArchiveExtractor(maxTotalBytes: 5000));
    aeRefuses(aeZip(aeRelease('2.0.1')), $dest);
});

it('rejects an archive without artisan, vendor or public/build', function () {
    $dest = aeDest();
    foreach (['artisan', 'vendor/autoload.php', 'public/build/manifest.json', 'VERSION'] as $missing) {
        $files = aeRelease();
        unset($files[$missing]);
        aeRefuses(aeZip($files), $dest);
    }
});

it('rejects a corrupt zip and a non-empty destination', function () {
    $dest = aeDest();
    $bad = sys_get_temp_dir().'/ignis-ae-bad-'.bin2hex(random_bytes(4)).'.zip';
    file_put_contents($bad, 'not a zip');
    aeRefuses($bad, $dest);
    @unlink($bad);

    mkdir($dest);
    file_put_contents("$dest/x", 'x');
    expect(fn () => (new ArchiveExtractor)->extract(aeZip(aeRelease()), $dest, '2.0.0'))
        ->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('archive_invalid'));
    expect(file_get_contents("$dest/x"))->toBe('x');
});
