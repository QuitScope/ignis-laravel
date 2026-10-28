<?php

namespace Modules\Mail\Support;

use RuntimeException;

/** A send the Mailer refuses: the translated message, the HTTP status (422, 409, 429) and the wait for 429. */
final class SendRefused extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}
