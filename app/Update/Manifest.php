<?php

namespace App\Update;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The signed `manifest.json` of a release. parse() only runs on bytes whose signature was checked, and still trusts
 * no field: every one is validated, so a bug in signing cannot hand us a path or a malformed hash.
 */
final readonly class Manifest
{
    public function __construct(
        public string $version,
        public string $archive,
        public string $sha256,
        public string $minVersion,
        public CarbonImmutable $createdAt,
        public ?string $image = null,
        public ?string $imageDigest = null,
    ) {}

    /** @throws UpdateException `manifest` on any missing or invalid field */
    public static function parse(string $json): self
    {
        $data = json_decode($json, true);
        if (! is_array($data) || array_is_list($data)) {
            throw new UpdateException('manifest');
        }
        $string = fn (string $key, string $pattern): string => is_string($data[$key] ?? null) && preg_match($pattern, $data[$key]) === 1
            ? $data[$key] : throw new UpdateException('manifest');
        $optional = fn (string $key, string $pattern): ?string => ($data[$key] ?? null) === null ? null : $string($key, $pattern);

        $version = $string('version', '/^\S+$/D');
        $minVersion = $string('min_version', '/^\S+$/D');
        if (! InstalledVersion::isValid($version) || ! InstalledVersion::isValid($minVersion)) {
            throw new UpdateException('manifest');
        }
        $created = $string('created_at', '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|\+00:00)$/D');
        try {
            $createdAt = CarbonImmutable::parse($created);
        } catch (Throwable) {
            throw new UpdateException('manifest');
        }
        if ($createdAt->utc()->format('Y-m-d\TH:i:s') !== substr($created, 0, 19)) {
            throw new UpdateException('manifest');
        }

        return new self(
            $version,
            $string('archive', '/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}$/D'),
            strtolower($string('sha256', '/^[0-9a-fA-F]{64}$/D')),
            $minVersion,
            $createdAt->utc(),
            $optional('image', '~^[a-z0-9.-]+(/[a-z0-9._-]+)+$~D'),
            $optional('image_digest', '/^sha256:[0-9a-f]{64}$/D'),
        );
    }
}
