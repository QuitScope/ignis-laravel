<?php

namespace App\Update;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Throwable;
use WeakMap;

/**
 * The only way the updater talks to GitHub. Everything that comes back is foreign data: https only, fixed hosts,
 * redirects followed by hand (at most three, each target checked against the allowlist), short timeouts, every body
 * read streamed up to a cap. A manifest counts only once its Ed25519 signature checks out; the archive only once its
 * SHA-256 matches that manifest. Any refusal is an UpdateException, thrown before anything on the system changes.
 */
final class ReleaseSource
{
    // release-assets.githubusercontent.com: where GitHub now redirects release asset downloads
    public const HOSTS = ['api.github.com', 'github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com'];

    public const MAX_REDIRECTS = 3;

    public const API_MAX_BYTES = 1024 * 1024;

    public const MANIFEST_MAX_BYTES = 64 * 1024;

    public const SIGNATURE_MAX_BYTES = 1024;

    /** @var WeakMap<Manifest, string> archive url per manifest object this instance verified */
    private WeakMap $verified;

    public function __construct()
    {
        $this->verified = new WeakMap;
    }

    /**
     * The latest stable release, null when the repository has none.
     *
     * @throws UpdateException
     */
    public function latest(): ?Release
    {
        if (! Installation::repoConfigured()) {
            throw new UpdateException('repo_missing');
        }
        $url = 'https://api.github.com/repos/'.config('ignis.release_repo').'/releases/latest';
        $response = $this->get($url, ['Accept' => 'application/vnd.github+json'], allowNotFound: true);
        if ($response === null) {
            return null;
        }
        $data = json_decode($this->read($response, self::API_MAX_BYTES), true);
        if (! is_array($data) || ! is_string($data['tag_name'] ?? null) || ! is_string($data['html_url'] ?? null)) {
            throw new UpdateException('bad_release');
        }
        if (($data['draft'] ?? true) !== false || ($data['prerelease'] ?? true) !== false) {
            throw new UpdateException('not_stable');
        }
        $tag = $data['tag_name'];
        $version = str_starts_with($tag, 'v') ? substr($tag, 1) : $tag;
        if (! InstalledVersion::isValid($version) || ! self::allowed($data['html_url'])) {
            throw new UpdateException('bad_release');
        }

        $assets = [];
        foreach (is_array($data['assets'] ?? null) ? $data['assets'] : [] as $asset) {
            if (is_array($asset) && is_string($asset['name'] ?? null) && is_string($asset['browser_download_url'] ?? null)) {
                if (! self::allowed($asset['browser_download_url'])) {
                    throw new UpdateException('host');
                }
                $assets[$asset['name']] = $asset['browser_download_url'];
            }
        }
        if (! isset($assets['manifest.json'], $assets['manifest.json.sig'])) {
            throw new UpdateException('assets_missing');
        }

        return new Release($tag, $version, $data['html_url'], $assets['manifest.json'], $assets['manifest.json.sig'], $assets);
    }

    /**
     * Manifest and signature of $release, checked: signature, tag = version, age, minimum version, newer than installed.
     *
     * @param  string|null  $installed  the installed version; null = InstalledVersion::current() (the updater container
     *                                  passes the version the app container runs)
     *
     * @throws UpdateException
     */
    public function fetchVerifiedManifest(Release $release, ?string $installed = null): Manifest
    {
        if (! SignatureVerifier::isConfigured()) {
            throw new UpdateException('key_missing');
        }
        $json = $this->read($this->get($release->manifestUrl), self::MANIFEST_MAX_BYTES);
        $signature = $this->read($this->get($release->signatureUrl), self::SIGNATURE_MAX_BYTES);
        if (! SignatureVerifier::verify($json, $signature)) {
            throw new UpdateException('signature');
        }

        $manifest = Manifest::parse($json);
        if ($manifest->version !== $release->version) {
            throw new UpdateException('version_mismatch');
        }
        if (! InstalledVersion::isStable($manifest->version)) {
            throw new UpdateException('not_stable');
        }
        $maxAge = (int) config('ignis.update.max_manifest_age_days', 180);
        if ($manifest->createdAt->lt(now()->subDays($maxAge)) || $manifest->createdAt->gt(now()->addDay())) {
            throw new UpdateException('manifest_age', ['days' => $maxAge]);
        }
        $installed ??= InstalledVersion::current();
        if (! InstalledVersion::isValid($installed)) {
            throw new UpdateException('dev_version');
        }
        if (InstalledVersion::compare($installed, $manifest->minVersion) < 0) {
            throw new UpdateException('min_version', ['version' => $manifest->minVersion]);
        }
        if (InstalledVersion::compare($manifest->version, $installed) <= 0) {
            throw new UpdateException('not_newer');
        }
        $archiveUrl = $release->archiveUrl($manifest->archive);
        if ($archiveUrl === null) {
            throw new UpdateException('archive_missing');
        }
        $this->verified[$manifest] = $archiveUrl;

        return $manifest;
    }

    /**
     * Streams the archive of a manifest object this instance returned from fetchVerifiedManifest() into a temporary
     * file next to $targetPath, checks size cap and SHA-256, then renames it to $targetPath. On failure only the
     * temporary file is removed; an existing $targetPath stays untouched.
     *
     * @throws UpdateException
     */
    public function downloadArchive(Manifest $manifest, string $targetPath): void
    {
        $url = $this->verified[$manifest] ?? throw new UpdateException('archive_missing');
        $max = (int) config('ignis.update.archive_max_bytes', 200 * 1024 * 1024);
        $temp = $targetPath.'.part-'.bin2hex(random_bytes(6));
        $out = null;
        try {
            $response = $this->get($url, timeout: 120);
            if ((int) $response->header('Content-Length') > $max) {
                throw new UpdateException('too_large');
            }
            $out = fopen($temp, 'xb') ?: throw new UpdateException('write');
            $hash = hash_init('sha256');
            $size = 0;
            $body = $this->body($response);
            while (! $body->eof()) {
                $chunk = $body->read(1024 * 1024);
                $size += strlen($chunk);
                if ($size > $max) {
                    throw new UpdateException('too_large');
                }
                hash_update($hash, $chunk);
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new UpdateException('write');
                }
            }
            fclose($out);
            $out = null;
            if (! hash_equals($manifest->sha256, hash_final($hash))) {
                throw new UpdateException('sha256');
            }
            if (! rename($temp, $targetPath)) {
                throw new UpdateException('write');
            }
        } catch (Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }
            if (is_file($temp)) {
                unlink($temp);
            }
            throw $e;
        }
    }

    private function http(int $timeout): PendingRequest
    {
        // identity: the caps count the bytes really held, no decompression bomb
        return Http::withUserAgent('IgnisLaravel')->withoutRedirecting()
            ->withHeaders(['Accept-Encoding' => 'identity'])
            ->withOptions(['stream' => true, 'decode_content' => false])
            ->timeout($timeout)->connectTimeout(5);
    }

    /**
     * GET with redirects followed by hand; each url (the first too) must be an allowed https host.
     *
     * @param  array<string, string>  $headers
     * @return ($allowNotFound is true ? Response|null : Response)
     */
    private function get(string $url, array $headers = [], int $timeout = 10, bool $allowNotFound = false): ?Response
    {
        for ($hop = 0; ; $hop++) {
            if (! self::allowed($url)) {
                throw new UpdateException('host');
            }
            try {
                $response = $this->http($timeout)->withHeaders($headers)->get($url);
            } catch (ConnectionException) {
                throw new UpdateException('unreachable');
            }
            if ($response->redirect()) {
                if ($hop >= self::MAX_REDIRECTS) {
                    throw new UpdateException('redirects');
                }
                $url = $response->header('Location');

                continue;
            }
            if ($allowNotFound && $response->status() === 404) {
                return null;
            }
            if ($response->status() !== 200) {
                throw new UpdateException('http', ['status' => $response->status()]);
            }

            return $response;
        }
    }

    private function read(Response $response, int $max): string
    {
        $body = $this->body($response);
        $raw = '';
        while (! $body->eof() && strlen($raw) <= $max) {
            $raw .= $body->read($max + 1 - strlen($raw));
        }
        if (strlen($raw) > $max) {
            throw new UpdateException('too_large');
        }

        return $raw;
    }

    private function body(Response $response): StreamInterface
    {
        $body = $response->toPsrResponse()->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $body;
    }

    /** A plain https url on one of the allowed hosts (no credentials, no other port). */
    private static function allowed(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower($parts['host'] ?? ''), self::HOSTS, true)
            && ! isset($parts['user']) && ! isset($parts['pass']) && in_array($parts['port'] ?? 443, [443], true);
    }
}
