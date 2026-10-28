<?php

namespace Modules\Mail\Support;

use Illuminate\Support\Collection;
use Modules\Mail\Models\MailList;

/** What RecipientResolver made of a header. */
final readonly class ResolvedRecipients
{
    /**
     * @param  array<int, string>  $roles  mailbox id => to|cc|bcc, one copy per mailbox
     * @param  list<string>  $unknown  addresses without a deliverable mailbox or list, normalized, in order
     * @param  Collection<int, MailList>  $lists  the lists the header named
     */
    public function __construct(public array $roles, public array $unknown, public Collection $lists) {}
}
