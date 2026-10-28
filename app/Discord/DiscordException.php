<?php

namespace App\Discord;

use RuntimeException;

/**
 * A Discord API call failed. The message is written for people (de/en, legacy texts) and may be shown on the page; it
 * never carries the token. `status` is the HTTP status (0 = network), getCode() Discord's JSON error code (50007 = the
 * user does not take DMs), `retryAfter` the seconds Discord asks to wait on 429.
 */
final class DiscordException extends RuntimeException
{
    public const CANNOT_DM = 50007;

    public function __construct(string $message, public readonly int $status = 0, int $code = 0, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message, $code);
    }

    public function retryable(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }
}
