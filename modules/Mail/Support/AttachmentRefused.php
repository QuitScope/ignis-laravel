<?php

namespace Modules\Mail\Support;

use RuntimeException;

/** An attachment AttachmentStorage refuses: the translated message (never a path) and the HTTP status (422, 404, 409). */
final class AttachmentRefused extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
