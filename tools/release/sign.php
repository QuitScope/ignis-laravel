<?php

/**
 * php tools/release/sign.php <archive> --key=<private-key-file> [--version=X] [--min-version=X]
 *                            [--image=ghcr.io/owner/name --image-digest=sha256:...]
 *
 * Writes manifest.json and manifest.json.sig (Ed25519, detached, Base64, over the exact bytes of manifest.json) next
 * to the archive, then checks its own output the way the updater does. The key is never printed.
 */
require __DIR__.'/../../vendor/autoload.php';

use App\Update\InstalledVersion;
use App\Update\Manifest;

$fail = function (string $message): never {
    fwrite(STDERR, "sign: $message\n");
    exit(1);
};

$archive = null;
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(key|version|min-version|image|image-digest)=(.*)$/s', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif (! str_starts_with($arg, '-') && $archive === null) {
        $archive = $arg;
    } else {
        $fail('unknown argument '.explode('=', $arg)[0]);
    }
}
if ($archive === null || ! is_file($archive)) {
    $fail('usage: php tools/release/sign.php <archive> --key=<private-key-file> (archive not found)');
}
$name = basename($archive);
$version = $opts['version'] ?? (preg_match('/^ignis-(.+)\.zip$/D', $name, $m) ? $m[1] : '');
$min = $opts['min-version'] ?? '0.0.0';
if (! InstalledVersion::isValid($version) || ! InstalledVersion::isValid($min)) {
    $fail('version and min-version must be strict SemVer (no leading v)');
}
if (isset($opts['image']) !== isset($opts['image-digest'])) {
    $fail('--image and --image-digest belong together');
}

$keyFile = $opts['key'] ?? '';
$raw = $keyFile !== '' && is_file($keyFile) ? @file_get_contents($keyFile) : false;
if ($raw === false) {
    $fail('the key file is missing or not readable (--key=<path>)');
}
$secret = base64_decode(trim($raw), true);
unset($raw);
if (! is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    $fail('the key file does not hold a private key made by keygen.php');
}

$sha256 = (string) hash_file('sha256', $archive);
$data = [
    'version' => $version,
    'archive' => $name,
    'sha256' => $sha256,
    'min_version' => $min,
    'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
];
if (isset($opts['image'])) {
    $data['image'] = $opts['image'];
    $data['image_digest'] = $opts['image-digest'];
}
$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
$raw = sodium_crypto_sign_detached($json, $secret);
$signature = base64_encode($raw);
$public = sodium_crypto_sign_publickey_from_secretkey($secret);
sodium_memzero($secret);

// the key the installations carry must be the one that signs
$configured = base64_decode(trim((string) @file_get_contents(__DIR__.'/../../resources/update/public.key')), true);
if (is_string($configured) && strlen($configured) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && ! hash_equals($configured, $public)) {
    $fail('this private key does not match resources/update/public.key: installations would reject the signature');
}

// the updater's own parsing and check, on the bytes we are about to write
try {
    $manifest = Manifest::parse($json);
} catch (Throwable) {
    $fail('the manifest is not valid (check --image and --image-digest)');
}
if (! sodium_crypto_sign_verify_detached($raw, $json, $public) || $manifest->sha256 !== $sha256) {
    $fail('self-check failed');
}

$dir = dirname($archive);
if (file_put_contents("$dir/manifest.json", $json) === false || file_put_contents("$dir/manifest.json.sig", $signature."\n") === false) {
    $fail('output cannot be written');
}
echo "Wrote $dir/manifest.json and $dir/manifest.json.sig for $version\n";
