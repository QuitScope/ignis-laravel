<?php

namespace App\Update;

/** What kind of installation this is and whether a click may update it (otherwise the page shows the reason). */
final class Installation
{
    public const REPO_PATTERN = '~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D';

    /** @return 'git_checkout'|'dev_version'|'repo_missing'|'key_missing'|'not_writable'|null */
    public static function selfUpdateBlocker(): ?string
    {
        $dir = self::appPath();

        return match (true) {
            file_exists("{$dir}/.git") => 'git_checkout',
            ! InstalledVersion::isValid(InstalledVersion::current($dir)) => 'dev_version',
            ! self::repoConfigured() => 'repo_missing',
            ! SignatureVerifier::isConfigured() => 'key_missing',
            self::driver() === 'files' && ! is_writable($dir) => 'not_writable',
            default => null,
        };
    }

    /** @return 'files'|'docker' */
    public static function driver(): string
    {
        $driver = config('ignis.update.driver');
        if ($driver === 'docker' || $driver === 'files') {
            return $driver;
        }

        return file_exists('/.dockerenv') ? 'docker' : 'files';
    }

    public static function repoConfigured(): bool
    {
        $repo = config('ignis.release_repo');

        return is_string($repo) && preg_match(self::REPO_PATTERN, $repo) === 1 && ! str_contains($repo, '..');
    }

    public static function appPath(): string
    {
        $path = config('ignis.update.app_path');
        $path = rtrim(is_string($path) && $path !== '' ? $path : base_path(), '/\\');
        $current = dirname($path, 2).'/current';
        if (! is_link($path) && basename(dirname($path)) === 'releases' && is_link($current)) {
            return $current;
        }

        return $path;
    }
}
