<?php

namespace App\Update;

/** The latest stable GitHub release as ReleaseSource::latest() found it; nothing here is verified yet. */
final readonly class Release
{
    /** @param array<string, string> $assets asset name => download url (allowed https hosts only) */
    public function __construct(
        public string $tag,
        public string $version,
        public string $htmlUrl,
        public string $manifestUrl,
        public string $signatureUrl,
        public array $assets,
    ) {}

    public function archiveUrl(string $name): ?string
    {
        return $this->assets[$name] ?? null;
    }
}
