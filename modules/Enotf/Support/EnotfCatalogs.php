<?php

namespace Modules\Enotf\Support;

use LogicException;

/**
 * Code lists of the protocol (legacy src/Crew/EnotfCatalogs). Single source for PHP and TS:
 * resources/js/lib/enotfCatalogs.json, as [code, label] pairs in form order (JS objects would sort integer keys).
 * Label conflicts between form and print: the form wins.
 * Befund/Reanimation/Diagnose/Zugang come from the legacy Crew/Catalogs + ReanimationCatalog (form labels; print-only legacy codes left out).
 */
final class EnotfCatalogs
{
    public const PATH = 'resources/js/lib/enotfCatalogs.json';

    /** @var array<string, array<int|string, string>>|null */
    private static ?array $all = null;

    /** @return array<string, array<int|string, string>> catalog => code => label, in form order */
    public static function all(): array
    {
        if (self::$all === null) {
            $raw = json_decode((string) file_get_contents(base_path(self::PATH)), true, flags: JSON_THROW_ON_ERROR);
            self::$all = array_map(fn (array $pairs) => array_column($pairs, 1, 0), $raw);
        }

        return self::$all;
    }

    /** @return array<int|string, string> */
    public static function get(string $name): array
    {
        return self::all()[$name] ?? throw new LogicException("Unknown eNOTF catalog {$name}");
    }

    public static function label(string $name, int|string|null $code): ?string
    {
        return $code === null ? null : (self::get($name)[$code] ?? null);
    }
}
