<?php

namespace App\Update;

use Illuminate\Support\Env;

/**
 * The installed version: the `VERSION` file a release archive ships (in the app directory), else `IGNIS_VERSION`
 * (Docker sets it), else config('ignis.version') (what was known when the config was built, e.g. IGNIS_VERSION from
 * .env under config:cache), else `dev`. Read live, so a swapped VERSION file shows without a config rebuild. Versions
 * are SemVer 2.0 without a leading `v`.
 */
final class InstalledVersion
{
    private const SEMVER = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';

    /** @param string|null $dir the app directory; default config('ignis.update.app_path'), else base_path() */
    public static function current(?string $dir = null): string
    {
        $dir ??= config('ignis.update.app_path') ?: base_path();
        $file = rtrim((string) $dir, '/\\').'/VERSION';
        $version = is_file($file) ? trim((string) file_get_contents($file)) : '';
        if ($version === '') {
            $version = trim((string) Env::get('IGNIS_VERSION', ''));
        }
        if ($version === '') {
            $configured = config('ignis.version');
            $version = is_string($configured) ? trim($configured) : '';
        }

        return $version !== '' ? $version : 'dev';
    }

    public static function isValid(string $version): bool
    {
        return preg_match(self::SEMVER, $version) === 1;
    }

    public static function isStable(string $version): bool
    {
        return self::isValid($version) && ! str_contains(explode('+', $version, 2)[0], '-');
    }

    /**
     * SemVer precedence: <0, 0 or >0 like strcmp (build metadata ignored).
     *
     * @throws UpdateException `bad_version` when one side is no SemVer
     */
    public static function compare(string $a, string $b): int
    {
        [$coreA, $preA] = self::split($a);
        [$coreB, $preB] = self::split($b);
        if ($coreA !== $coreB) {
            return $coreA <=> $coreB;
        }
        if ($preA === [] || $preB === []) {
            return count($preB) <=> count($preA);
        }
        foreach ($preA as $i => $partA) {
            if (! isset($preB[$i])) {
                return 1;
            }
            $partB = $preB[$i];
            $numA = ctype_digit($partA);
            $numB = ctype_digit($partB);
            $cmp = match (true) {
                $numA && $numB => (int) $partA <=> (int) $partB,
                $numA => -1,
                $numB => 1,
                default => strcmp($partA, $partB) <=> 0,
            };
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return count($preA) < count($preB) ? -1 : 0;
    }

    /** @return array{0: array{int, int, int}, 1: list<string>} */
    private static function split(string $version): array
    {
        if (preg_match(self::SEMVER, $version, $m) !== 1) {
            throw new UpdateException('bad_version');
        }

        return [[(int) $m[1], (int) $m[2], (int) $m[3]], isset($m[4]) ? explode('.', $m[4]) : []];
    }
}
