<?php

namespace App\Documents;

use RuntimeException;

/** Document JSON the validator refuses; `reasons` are user-facing sentences. */
final class InvalidDocument extends RuntimeException
{
    /** @param list<string> $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }
}
