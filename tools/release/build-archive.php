<?php

/**
 * php tools/release/build-archive.php <version> [--out=<dir>] [--root=<dir>] [--no-checks]
 *
 * Builds ignis-<version>.zip (root directory ignis-<version>/) from the working tree. Run `composer install --no-dev
 * --optimize-autoloader` and `npm ci && npm run build` first. --root and --no-checks exist for the tests.
 */
require __DIR__.'/../../vendor/autoload.php';

use App\Update\FlatSwapper;
use App\Update\InstalledVersion;

$fail = function (string $message): never {
    fwrite(STDERR, "build-archive: $message\n");
    exit(1);
};

$version = null;
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(out|root)=(.+)$/s', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif ($arg === '--no-checks') {
        $opts['no-checks'] = true;
    } elseif (! str_starts_with($arg, '-') && $version === null) {
        $version = $arg;
    } else {
        $fail("unknown argument $arg");
    }
}
if ($version === null || ! InstalledVersion::isValid($version)) {
    $fail('usage: php tools/release/build-archive.php <version> (strict SemVer, no leading v)');
}
$root = realpath($opts['root'] ?? __DIR__.'/../..') ?: $fail('--root does not exist');
$out = $opts['out'] ?? getcwd();
if (! is_dir($out) && ! @mkdir($out, 0775, true)) {
    $fail('output directory cannot be created');
}
$zipPath = realpath($out).DIRECTORY_SEPARATOR."ignis-$version.zip";
$checks = ! isset($opts['no-checks']);

if ($checks) {
    if (! is_dir("$root/public/build")) {
        $fail('public/build is missing: run `npm ci && npm run build` first');
    }
    // without the real public key no installation made from this archive could ever update by click
    $key = base64_decode(trim((string) @file_get_contents("$root/resources/update/public.key")), true);
    if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        $fail('resources/update/public.key is not a public key (still PLACEHOLDER?): run keygen.php, put the public key there and commit it first');
    }
    $installed = json_decode((string) @file_get_contents("$root/vendor/composer/installed.json"), true);
    if (! is_array($installed) || ($installed['dev'] ?? true) !== false) {
        $fail('vendor/ was not installed with --no-dev: run `composer install --no-dev --optimize-autoloader`');
    }
    foreach (['phpunit', 'pestphp'] as $dev) {
        if (is_dir("$root/vendor/$dev")) {
            $fail("vendor/$dev is a dev package: run `composer install --no-dev --optimize-autoloader`");
        }
    }
}

// never in a release: secrets, VCS data, tests, local state, tooling
$skip = function (string $rel): bool {
    foreach (explode('/', $rel) as $part) {
        if ($rel === '.env.example') {
            break;
        }
        if (str_starts_with($part, '.env') || str_starts_with($part, '.git') || $part === 'node_modules') {
            return true;
        }
    }

    return str_ends_with($rel, '.sqlite') || str_ends_with($rel, '.sqlite-journal')
        || str_ends_with($rel, '.sqlite-wal') || str_ends_with($rel, '.sqlite-shm')
        || preg_match('~^(tests|storage|tools|updater|docs/screenshots|bootstrap/cache|public/storage|public/hot)(/|$)~', $rel) === 1;
};

$files = [];
$walk = function (string $rel) use (&$walk, &$files, $root, $skip): void {
    if ($skip($rel) || is_link("$root/$rel")) {
        return;
    }
    if (is_dir("$root/$rel")) {
        foreach (scandir("$root/$rel") ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $walk("$rel/$name");
            }
        }
    } elseif (is_file("$root/$rel")) {
        $files[] = $rel;
    }
};
foreach (FlatSwapper::CODE as $entry) {
    if ($entry !== 'VERSION') {
        $walk($entry);
    }
}
$walk('.env.example'); // the template for the first install; the extractor ignores it (not in CODE)
sort($files, SORT_STRING);
if ($checks && ! in_array('artisan', $files, true)) {
    $fail('artisan is missing');
}

if (file_exists($zipPath)) {
    @unlink($zipPath);
}
$zip = new ZipArchive;
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    $fail('archive cannot be created');
}
$prefix = "ignis-$version";
$mtime = 1577836800; // fixed: the same tree gives the same zip
$add = function (string $name, int $mode) use ($zip, $mtime): void {
    $zip->setMtimeName($name, $mtime);
    $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, $mode << 16);
};
$zip->addFromString("$prefix/VERSION", $version."\n");
$add("$prefix/VERSION", 0100644);
foreach ($files as $rel) {
    $zip->addFile("$root/$rel", "$prefix/$rel");
    $add("$prefix/$rel", $rel === 'artisan' || str_starts_with($rel, 'vendor/bin/') ? 0100755 : 0100644);
}
if (! $zip->close()) {
    @unlink($zipPath);
    $fail('archive cannot be written');
}
echo "Built $zipPath (".(count($files) + 1)." files)\n";
