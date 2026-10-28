<?php

/**
 * One-time: creates the Ed25519 release key pair.
 *
 *   php tools/release/keygen.php <path-for-the-private-key>
 *
 * The private key (Base64) goes ONLY to the given path: never inside this repository, never over an existing file.
 * It is not printed. The public key (Base64) is printed; it belongs into resources/update/public.key.
 */
$fail = function (string $message): never {
    fwrite(STDERR, "keygen: $message\n");
    exit(1);
};

$target = $argv[1] ?? '';
if ($target === '' || str_starts_with($target, '-')) {
    $fail('usage: php tools/release/keygen.php <path-for-the-private-key>');
}
$dir = realpath(dirname($target));
if ($dir === false || ! is_dir($dir)) {
    $fail('the directory of that path does not exist');
}
$repo = realpath(__DIR__.'/../..');
$norm = fn (string $p): string => rtrim(PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $p)) : str_replace('\\', '/', $p), '/').'/';
if (str_starts_with($norm($dir), $norm((string) $repo))) {
    $fail('refusing to write the private key inside the repository; choose a path outside of it');
}
$path = $dir.DIRECTORY_SEPARATOR.basename($target);
if (file_exists($path) || is_link($path)) {
    $fail('that file already exists; refusing to overwrite it');
}

$pair = sodium_crypto_sign_keypair();
$secret = base64_encode(sodium_crypto_sign_secretkey($pair));
$public = base64_encode(sodium_crypto_sign_publickey($pair));
sodium_memzero($pair);

// x: fails if the file appeared in the meantime
$handle = @fopen($path, 'xb');
if ($handle === false) {
    $fail('the private key cannot be written there');
}
$ok = fwrite($handle, $secret."\n") === strlen($secret) + 1;
fclose($handle);
if (! $ok) {
    @unlink($path);
    $fail('the private key cannot be written there');
}
@chmod($path, 0600);

echo "Private key written to $path (keep it offline, back it up, NEVER commit it).\n\n";
echo "Public key (Base64):\n$public\n\n";
echo "Put exactly this line into resources/update/public.key (replacing PLACEHOLDER), then commit that file.\n";
