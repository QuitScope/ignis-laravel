<?php

namespace App\Announcements;

/** Outcome of one feed refresh: `message` is `ok` or the short error code kept in the settings (never a response body). */
final readonly class RefreshResult
{
    public function __construct(
        public bool $ok,
        public string $message = 'ok',
        public int $count = 0,
        public int $dropped = 0,
    ) {}
}
