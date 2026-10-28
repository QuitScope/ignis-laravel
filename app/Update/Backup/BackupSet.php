<?php

namespace App\Update\Backup;

use App\Update\UpdateException;
use Carbon\CarbonImmutable;
use Throwable;

/** One backup directory, described by its manifest.json. */
final class BackupSet
{
    /**
     * manifest.json `format` this code writes and restores. A newer version may only change the layout together with
     * a new number, so an older version refuses (backup_format) instead of misreading it.
     */
    public const FORMAT = 1;

    /**
     * Throws backup_format unless the manifest is FORMAT.
     *
     * @param  array<string, mixed>  $manifest
     */
    public static function requireFormat(array $manifest): void
    {
        if (($manifest['format'] ?? null) !== self::FORMAT) {
            throw new UpdateException('backup_format', ['format' => (string) json_encode($manifest['format'] ?? null)]);
        }
    }

    public function __construct(
        public readonly string $path,
        public readonly CarbonImmutable $createdAt,
        public readonly string $fromVersion,
        public readonly string $toVersion,
        public readonly int $sizeBytes,
    ) {}

    public static function fromDir(string $dir): ?self
    {
        $m = self::readManifest($dir);
        if ($m === null) {
            return null;
        }
        try {
            return new self($dir, CarbonImmutable::parse((string) $m['created_at']), (string) $m['from'], (string) $m['to'], (int) $m['size_bytes']);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    public static function readManifest(string $dir): ?array
    {
        $raw = @file_get_contents($dir.'/manifest.json');
        $m = $raw === false ? null : json_decode($raw, true);

        return is_array($m) && isset($m['created_at'], $m['from'], $m['to'], $m['size_bytes'], $m['files']) && is_array($m['files']) ? $m : null;
    }
}
