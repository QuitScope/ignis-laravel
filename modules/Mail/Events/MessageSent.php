<?php

namespace Modules\Mail\Events;

use App\Models\User;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailMessage;

/** A mail went out; dispatched after the send transaction committed (the bell listens). */
final readonly class MessageSent
{
    public function __construct(public MailMessage $message, public Mailbox $from, public User $by) {}
}
