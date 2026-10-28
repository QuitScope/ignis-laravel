<?php

namespace Modules\Mail\Support;

use Modules\Mail\Models\MailDelivery;

/**
 * THE BCC rule (legacy visibleHeader): the sender copy sees the whole header, a bcc copy only its own
 * mailbox, every other copy no bcc at all. Pages, props and search build the header only here, never from the raw
 * `header` column.
 */
final class HeaderVisibility
{
    /** @return array{to: list<string>, cc: list<string>, bcc: list<string>} */
    public static function for(MailDelivery $delivery): array
    {
        $header = $delivery->message->header ?? [];
        $list = fn (string $key) => array_values(array_filter((array) ($header[$key] ?? []), 'is_string'));

        return [
            'to' => $list('to'),
            'cc' => $list('cc'),
            'bcc' => match ($delivery->role) {
                'sender' => $list('bcc'),
                'bcc' => [$delivery->mailbox->address],
                default => [],
            },
        ];
    }
}
