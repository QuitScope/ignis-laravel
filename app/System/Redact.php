<?php

namespace App\System;

/** Output of tasks, failed jobs and update logs leaves the server only through here: webhook URL tails, bot tokens, bearer tokens, password assignments, the app key, the updater secret and database passwords are blanked first, then it is cut. */
class Redact
{
    public const OUTPUT_BYTES = 4096;

    public static function text(string $s, int $maxBytes = self::OUTPUT_BYTES): string
    {
        $s = str_replace(self::secrets(), '[redacted]', $s);
        $s = preg_replace(
            ['~api/webhooks/\d+/\S+~', '~Bot\s+\S+~', '~Bearer\s+\S+~i', '~((?:password|passwd|pwd)\s*=\s*)(?:"[^"]*"|\'[^\']*\'|\S+)~i', '~("(?:password|passwd|pwd|secret)"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"~i'],
            ['api/webhooks/[redacted]', 'Bot [redacted]', 'Bearer [redacted]', '$1[redacted]', '$1"[redacted]"'],
            $s,
        ) ?? '';

        return mb_strcut($s, 0, $maxBytes);
    }

    public static function summary(string $s): string
    {
        return mb_substr(self::text(strtok($s, "\n") ?: ''), 0, 200);
    }

    /**
     * Secrets shorter than 4 characters are ignored (they would blank ordinary text). Longest first, so the full key goes before its payload.
     *
     * @return list<string>
     */
    private static function secrets(): array
    {
        if (! app()->bound('config')) {
            return [];
        }
        $key = (string) config('app.key');
        $values = [$key, str_starts_with($key, 'base64:') ? substr($key, 7) : '', (string) config('ignis.update.updater_secret')];
        foreach ((array) config('database.connections') as $c) {
            $values[] = is_array($c) ? (string) ($c['password'] ?? '') : '';
        }
        $values = array_values(array_filter(array_unique($values), fn (string $v) => strlen($v) >= 4));
        usort($values, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return $values;
    }
}
