<?php

namespace Modules\Mail\Support;

use InvalidArgumentException;
use Modules\Mail\Content\MailContentValidator;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailMessage;

/**
 * Reply, reply all and forward as a prefilled compose form (rebuild of the package ReplyBuilder). Only
 * for a SENT original the caller has a copy of (ComposeController checks that through MailDelivery::visibleTo); thread and
 * in_reply_to are taken from the original when the draft is stored (DraftController), never from the client.
 * Recipients never include BCC; "me" is the mailbox writing the reply.
 */
final class ReplyBuilder
{
    /**
     * @return array{subject: string, to: list<string>, cc: list<string>, body: array<string, mixed>}
     */
    public static function reply(MailMessage $original, Mailbox $as): array
    {
        $sender = $original->senderMailbox->address;
        $to = self::same($sender, $as->address) ? self::without($original->header['to'] ?? [], $as->address) : [$sender];

        return ['subject' => self::prefix('Re:', $original->subject), 'to' => $to, 'cc' => [], 'body' => self::quote($original, 'mail.reply.header')];
    }

    /**
     * @return array{subject: string, to: list<string>, cc: list<string>, body: array<string, mixed>}
     */
    public static function replyAll(MailMessage $original, Mailbox $as): array
    {
        $to = self::without([$original->senderMailbox->address, ...($original->header['to'] ?? [])], $as->address);
        $cc = array_values(array_filter(self::without($original->header['cc'] ?? [], $as->address), fn (string $a) => ! in_array(mb_strtolower($a), array_map('mb_strtolower', $to), true)));

        return ['subject' => self::prefix('Re:', $original->subject), 'to' => $to, 'cc' => $cc, 'body' => self::quote($original, 'mail.reply.header')];
    }

    /**
     * Forwarding: no recipients. The attachments are copied as new files when the draft is stored (forward_from).
     *
     * @return array{subject: string, to: list<string>, cc: list<string>, body: array<string, mixed>}
     */
    public static function forward(MailMessage $original): array
    {
        return ['subject' => self::prefix('Fwd:', $original->subject), 'to' => [], 'cc' => [], 'body' => self::quote($original, 'mail.forward.header')];
    }

    public static function prefix(string $prefix, string $subject): string
    {
        $subject = trim($subject);
        $full = str_starts_with(mb_strtolower($subject), mb_strtolower($prefix)) ? $subject : $prefix.' '.$subject;

        return mb_substr($full, 0, SendLimits::SUBJECT_MAX);
    }

    /**
     * An empty first line to write in, then the original as a quote with a header line; deeper quotes are cut.
     *
     * @return array<string, mixed>
     */
    private static function quote(MailMessage $original, string $headerKey): array
    {
        $at = ($original->sent_at ?? $original->created_at ?? now())->copy()->timezone((string) config('ignis.timezone'));
        $header = __($headerKey, [
            'date' => $at->format('d.m.Y H:i'),
            'name' => $original->senderMailbox->display_name,
            'address' => $original->senderMailbox->address,
        ]);
        try {
            $quoted = MailContentValidator::normalize($original->body ?? ['type' => 'doc', 'content' => []])['content'];
        } catch (InvalidArgumentException) {
            $quoted = [];
        }

        return MailContentValidator::capQuotes(['type' => 'doc', 'content' => [
            ['type' => 'paragraph'],
            ['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $header]]], ...$quoted]],
        ]]);
    }

    /**
     * @param  array<mixed>  $addresses
     * @return list<string> without $me and duplicates (case-insensitive), first spelling kept
     */
    private static function without(array $addresses, string $me): array
    {
        $out = [];
        foreach ($addresses as $a) {
            if (is_string($a) && trim($a) !== '' && ! self::same($a, $me)) {
                $out[mb_strtolower(trim($a))] ??= trim($a);
            }
        }

        return array_values($out);
    }

    private static function same(string $a, string $b): bool
    {
        return AddressRules::normalize($a) === AddressRules::normalize($b);
    }
}
