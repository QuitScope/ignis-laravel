<?php

namespace App\Dashboard;

/**
 * The target of a quick link, rendered in an `href` for every signed-in user: so only three shapes pass.
 *  - a path of this installation: one `/` first, never `//` (protocol-relative) or a backslash,
 *  - `http://` / `https://` with a real host and no credentials,
 *  - `mailto:` with one plain address (optional `?subject=…`, no `cc`, `bcc`, `body` or other parameters).
 * Anything else (`javascript:`, `data:`, `vbscript:`, `file:`, mixed-case schemes, whitespace or control characters
 * anywhere, more than 255 characters) is refused. Schemes match case-sensitively on purpose: the legacy writer
 * produced lower case only, and a strict rule leaves no parser difference to exploit.
 *
 * Shared by the settings requests and the legacy importer.
 */
final class LinkTarget
{
    public const MAX = 255;

    public static function normalise(string $url): ?string
    {
        $url = trim($url, ' ');

        return self::allowed($url) ? $url : null;
    }

    public static function validate(string $url): bool
    {
        return self::normalise($url) === $url;
    }

    private static function allowed(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX) {
            return false;
        }
        if (preg_match('~[\p{C}\p{Z}\\\\]~u', $url) !== 0) {
            return false;
        }
        if (preg_match('~^/(?!/)~', $url) === 1) {
            return true;
        }
        if (preg_match('~^mailto:[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}(?:\?subject=[A-Za-z0-9%._+-]*)?$~', $url) === 1) {
            return true;
        }
        if (preg_match('~^https?://[^/?#@]~', $url) !== 1) {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || ! isset($parts['host'])) {
            return false;
        }

        return self::host($parts['host']);
    }

    private static function host(string $host): bool
    {
        if (str_starts_with($host, '[')) {
            return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return preg_match('~^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*$~', $host) === 1;
    }
}
