<?php

namespace Modules\Mail\Support;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Modules\Mail\Events\MessageSent;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailDelivery;
use Modules\Mail\Models\MailMessage;

/**
 * Sends a draft (rebuild of the package Mailer and legacy sendDraft): checks, then one transaction with
 * the message row locked (no double send, a parallel attachment upload waits), the send pause under the mailbox lock,
 * the copies per mailbox and role, the sender copy to "sent". The sender is always $from, the mailbox the caller chose
 * from MailboxAccess::accessible() and the draft was written in; `sent_by_user_id` is the writing account. MessageSent
 * (the bell) only after the commit.
 */
final class Mailer
{
    public const ATTEMPTS = 3;

    public function __construct(private readonly RecipientResolver $resolver, private readonly MailBodyGuard $guard, private readonly Settings $settings) {}

    /**
     * @param  array<string, mixed>  $body
     * @param  array{to: list<string>, cc: list<string>, bcc: list<string>}  $header
     *
     * @throws SendRefused
     */
    public function send(MailMessage $draft, Mailbox $from, User $by, string $subject, array $body, array $header): ResolvedRecipients
    {
        if (trim($subject) === '') {
            throw new SendRefused(__('mail.send.no_subject'));
        }
        if ($header['to'] === [] && $header['cc'] === [] && $header['bcc'] === []) {
            throw new SendRefused(__('mail.send.no_recipients'));
        }
        $resolved = $this->resolver->resolve($header);
        $restricted = $resolved->lists->where('senders', 'managers')->sortBy('name')->first();
        if ($restricted !== null && ! $by->hasPermission(['admin', 'mail.lists.manage'])) {
            throw new SendRefused(__('mail.send.list_managers_only', ['address' => $restricted->address]));
        }
        if (count($resolved->roles) > SendLimits::RESOLVED_MAX) {
            throw new SendRefused(__('mail.send.too_many_resolved', ['max' => SendLimits::RESOLVED_MAX]));
        }
        if ($resolved->roles === []) {
            throw new SendRefused($resolved->unknown === []
                ? __('mail.send.empty_lists')
                : __('mail.send.undeliverable', ['addresses' => implode(', ', $resolved->unknown)]));
        }

        // Read before the transaction: inside it, nothing may be read without a lock before the mailbox lock.
        $cooldown = (int) $this->settings->get('mail.send_cooldown');

        // Retried on a deadlock (A→B while B→A locks the same rows in the other order): the closure only writes rows and
        // registers the event after the commit, so a rolled back attempt leaves nothing behind.
        return DB::transaction(function () use ($draft, $from, $by, $subject, $body, $header, $resolved, $cooldown) {
            $message = MailMessage::query()->whereKey($draft->id)->lockForUpdate()->first();
            if ($message === null || $message->status !== 'draft' || $message->sender_mailbox_id !== $from->id) {
                throw new SendRefused(__('mail.send.already_sent'), 409);
            }
            $wait = SendLimits::cooldownWait($from, $cooldown);
            if ($wait > 0) {
                throw new SendRefused(trans_choice('mail.send.cooldown', $wait, ['seconds' => $wait]), 429, $wait);
            }

            $now = now();
            $message->forceFill([
                'subject' => trim($subject), 'body' => $body, 'header' => $header, 'search_text' => $this->guard->searchText(trim($subject), $body),
                'status' => 'sent', 'sent_at' => $now, 'sent_by_user_id' => $by->id,
            ])->save();
            MailDelivery::query()->where('message_id', $message->id)->where('mailbox_id', $from->id)->where('role', 'sender')
                ->update(['folder' => 'sent', 'sorted_at' => $now, 'deleted_at' => null, 'updated_at' => $now]);
            MailDelivery::query()->insert(array_map(fn (int $mailbox, string $role) => [
                'message_id' => $message->id, 'mailbox_id' => $mailbox, 'role' => $role, 'folder' => 'inbox',
                'is_flagged' => false, 'sorted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ], array_keys($resolved->roles), $resolved->roles));

            DB::afterCommit(fn () => event(new MessageSent($message, $from, $by)));

            return $resolved;
        }, self::ATTEMPTS);
    }
}
