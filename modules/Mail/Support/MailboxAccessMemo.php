<?php

namespace Modules\Mail\Support;

use Illuminate\Support\Collection;
use Modules\Mail\Models\Mailbox;

/**
 * Request-scoped memo of MailboxAccess::accessible() per account id (bound with app()->scoped in the provider, so
 * Octane and queue jobs start empty; no static state). Invalidate through MailboxAccess::forget().
 */
final class MailboxAccessMemo
{
    /** @var array<int, Collection<int, Mailbox>> */
    public array $accessible = [];
}
