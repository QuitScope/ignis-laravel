<?php

namespace App\Support;

final class SafeRedirect
{
    /**
     * Returns $target only if it is an internal path ("/…"): not protocol-relative ("//…"),
     * no backslash and no control characters (browsers drop tabs/newlines, "/\t/x" → "//x").
     */
    public static function intended(?string $target, string $fallback = '/'): string
    {
        if ($target === null
            || ! str_starts_with($target, '/')
            || str_starts_with($target, '//')
            || str_contains($target, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $target)) {
            return $fallback;
        }

        return $target;
    }
}
