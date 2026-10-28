<?php

namespace App\Vehicles;

/**
 * Outcome of one registry run. `error` set means nothing was stored and the queue is untouched; the caller answers
 * with a 4xx then (docs/dev/vehicle-registry.md).
 */
final readonly class IntakeResult
{
    public const TOO_MANY_ROWS = 'too_many_rows';

    public function __construct(
        public int $received,
        public int $stored,
        public int $skipped,
        public ?string $error = null,
    ) {}
}
