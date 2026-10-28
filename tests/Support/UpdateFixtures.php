<?php

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Fake signed releases for the updater tests: an Ed25519 key pair made at runtime (never a real key), a signed
 * manifest, a zip and the GitHub answers (Http::fake). useKey() puts the public half into config.
 */
final class UpdateFixtures
{
    public const REPO = 'ignis-test/ignis';

    public const API = 'https://api.github.com/repos/ignis-test/ignis/releases/latest';

    private static ?string $keypair = null;

    /** @var list<string> temporary app directories, removed when the test process ends */
    private static array $dirs = [];

    public static function secretKey(): string
    {
        self::$keypair ??= sodium_crypto_sign_keypair();

        return sodium_crypto_sign_secretkey(self::$keypair);
    }

    public static function publicKey(): string
    {
        self::secretKey();

        return base64_encode(sodium_crypto_sign_publickey((string) self::$keypair));
    }

    public static function setUp(string $installed = '1.0.0'): string
    {
        config(['ignis.update.public_key' => self::publicKey(), 'ignis.release_repo' => self::REPO]);

        return self::appDir($installed);
    }

    public static function appDir(?string $version = '1.0.0'): string
    {
        $dir = sys_get_temp_dir().'/ignis-update-'.Str::random(10);
        mkdir($dir);
        if (self::$dirs === []) {
            register_shutdown_function(fn () => array_map(fn (string $d) => (new Filesystem)->deleteDirectory($d), self::$dirs));
        }
        self::$dirs[] = $dir;
        if ($version !== null) {
            file_put_contents("$dir/VERSION", $version."\n");
        }
        config(['ignis.update.app_path' => $dir]);

        return $dir;
    }

    public static function sign(string $payload): string
    {
        return base64_encode(sodium_crypto_sign_detached($payload, self::secretKey()));
    }

    /** @param array<string, mixed> $overrides */
    public static function manifest(array $overrides = [], string $archive = 'zip-bytes'): string
    {
        return (string) json_encode($overrides + [
            'version' => '2.0.0',
            'archive' => 'ignis-2.0.0.zip',
            'sha256' => hash('sha256', $archive),
            'min_version' => '1.0.0',
            'created_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ], JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, string> $files */
    public static function zip(array $files = ['VERSION' => "2.0.0\n"]): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ignis-zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    public static function assetUrl(string $name, string $tag = 'v2.0.0'): string
    {
        return 'https://github.com/'.self::REPO."/releases/download/{$tag}/{$name}";
    }

    /**
     * The GitHub answer for releases/latest.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function release(array $overrides = [], string $tag = 'v2.0.0'): array
    {
        return $overrides + [
            'tag_name' => $tag,
            'html_url' => 'https://github.com/'.self::REPO."/releases/tag/{$tag}",
            'draft' => false,
            'prerelease' => false,
            'assets' => array_map(fn (string $n) => ['name' => $n, 'browser_download_url' => self::assetUrl($n, $tag)],
                ['manifest.json', 'manifest.json.sig', 'ignis-2.0.0.zip']),
        ];
    }

    /**
     * Fakes a complete release. The manifest is signed with the test key unless $signature is given.
     *
     * @param  array<string, mixed>  $manifest  manifest overrides
     * @param  array<string, mixed>  $release  release overrides
     * @param  array<string, mixed>  $extra  more Http::fake patterns (win over the defaults)
     */
    public static function fake(array $manifest = [], array $release = [], ?string $signature = null, string $archive = 'zip-bytes', array $extra = []): void
    {
        $json = self::manifest($manifest, $archive);
        Http::fake($extra + [
            self::API => Http::response(self::release($release)),
            self::assetUrl('manifest.json') => Http::response($json),
            self::assetUrl('manifest.json.sig') => Http::response($signature ?? self::sign($json)),
            self::assetUrl('ignis-2.0.0.zip') => Http::response($archive),
        ]);
    }
}
