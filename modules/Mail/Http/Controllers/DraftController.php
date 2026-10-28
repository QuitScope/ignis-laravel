<?php

namespace Modules\Mail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Mail\Http\Requests\SaveDraftRequest;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailDelivery;
use Modules\Mail\Models\MailMessage;
use Modules\Mail\Support\AttachmentStorage;
use Modules\Mail\Support\MailBodyGuard;
use Modules\Mail\Support\MailboxAccess;
use Modules\Mail\Support\Mailer;
use Modules\Mail\Support\SendRefused;

/**
 * Drafts and sending (legacy createDraft/updateDraft/sendDraft). `{message}` is bound through MailDelivery::visibleTo
 * (MailServiceProvider), so a mail the account does not take part in is a 404 for everybody, admin and super admin
 * included. The mailbox is `mailbox_id` of the post, else the chosen one; only from MailboxAccess::accessible(), a
 * foreign one is a 403, never the own one as a substitute. A draft is only edited and sent in the mailbox it belongs to.
 */
class DraftController extends Controller
{
    public function __construct(private readonly MailBodyGuard $guard, private readonly AttachmentStorage $attachments) {}

    /** POST /mail/drafts: the first autosave creates the draft (composing itself writes nothing). */
    public function store(SaveDraftRequest $request): JsonResponse
    {
        $user = $request->user();
        $mailbox = $this->mailbox($request);
        $body = $this->body($request);

        // Reply and forward only on sent mail with a copy in this mailbox; the thread comes from there, never from the client.
        $original = null;
        $forwarded = null;
        foreach (['in_reply_to', 'forward_from'] as $key) {
            if ($request->filled($key)) {
                $original = MailMessage::query()->whereKey($request->integer($key))->where('status', 'sent')
                    ->whereHas('deliveries', fn ($q) => $q->visibleTo($user)->where('mailbox_id', $mailbox->id))->first()
                    ?? abort(404);
                $forwarded = $key === 'forward_from' ? $original : null;
            }
        }

        $subject = trim((string) $request->validated('subject'));
        $message = DB::transaction(function () use ($mailbox, $user, $subject, $body, $request, $original) {
            $message = MailMessage::query()->forceCreate([
                'sender_mailbox_id' => $mailbox->id, 'sent_by_user_id' => $user->id, 'subject' => $subject,
                'body' => $body ?? ['type' => 'doc', 'content' => []], 'search_text' => $this->guard->searchText($subject, $body),
                'header' => $request->recipients(), 'thread_id' => $original->thread_id ?? bin2hex(random_bytes(16)),
                'in_reply_to' => $original?->id, 'status' => 'draft',
            ]);
            MailDelivery::query()->forceCreate(['message_id' => $message->id, 'mailbox_id' => $mailbox->id, 'role' => 'sender', 'folder' => 'drafts', 'sorted_at' => now()]);

            return $message;
        });
        $attachments = $forwarded === null ? [] : $this->attachments->copyAll($forwarded, $message);

        return response()->json(['messageId' => $message->id, 'attachments' => array_map(AttachmentStorage::ref(...), $attachments)], 201);
    }

    /** PUT /mail/drafts/{message}: autosave in place; only the posted fields change. */
    public function update(SaveDraftRequest $request, MailMessage $message): JsonResponse
    {
        $mailbox = $this->mailbox($request);
        $this->ownDraft($message, $mailbox);
        $body = $this->body($request);

        // Under the row lock and checked again (as Mailer::send): an autosave still in flight when the send commits
        // must not overwrite the delivered mail.
        $saved = DB::transaction(function () use ($request, $message, $mailbox, $body) {
            $draft = MailMessage::query()->whereKey($message->id)->lockForUpdate()->first();
            if ($draft === null || $draft->status !== 'draft' || $draft->sender_mailbox_id !== $mailbox->id) {
                return false;
            }
            if ($request->has('subject')) {
                $draft->subject = trim((string) $request->validated('subject'));
            }
            if ($body !== null) {
                $draft->body = $body;
            }
            if ($request->hasRecipients()) {
                $draft->header = $request->recipients();
            }
            $draft->forceFill(['search_text' => $this->guard->searchText($draft->subject, $draft->body), 'sent_by_user_id' => $request->user()->id])->save();
            $draft->deliveries()->where('role', 'sender')->where('folder', 'drafts')->update(['sorted_at' => now()]);

            return true;
        });

        return $saved
            ? response()->json(['messageId' => $message->id])
            : response()->json(['message' => __('mail.send.already_sent')], 409);
    }

    /** POST /mail/drafts/{message}/send: the same row is sent (its id stays); fields not posted come from the draft. */
    public function send(SaveDraftRequest $request, MailMessage $message, Mailer $mailer): JsonResponse
    {
        $mailbox = $this->mailbox($request);
        if ($message->status === 'sent' && $message->sender_mailbox_id === $mailbox->id) {
            return response()->json(['message' => __('mail.send.already_sent')], 409);
        }
        $this->ownDraft($message, $mailbox);
        $body = $this->body($request);

        /** @var User $user */
        $user = $request->user();
        $stored = $message->header ?? [];
        $header = $request->hasRecipients() ? $request->recipients() : [
            'to' => $stored['to'] ?? [], 'cc' => $stored['cc'] ?? [], 'bcc' => $stored['bcc'] ?? [],
        ];
        $subject = $request->has('subject') ? (string) $request->validated('subject') : $message->subject;

        try {
            $resolved = $mailer->send($message, $mailbox, $user, $subject, $body ?? $message->body ?? ['type' => 'doc', 'content' => []], $header);
        } catch (SendRefused $e) {
            $response = response()->json(['message' => $e->getMessage()] + ($e->retryAfter === null ? [] : ['retry_after' => $e->retryAfter]), $e->status);

            return $e->retryAfter === null ? $response : $response->header('Retry-After', (string) $e->retryAfter);
        }

        return response()->json(['messageId' => $message->id, 'unresolvedAddresses' => $resolved->unknown]);
    }

    /** The mailbox of this post; 403 when the account may not write from it or has none (legacy noMailbox). */
    private function mailbox(SaveDraftRequest $request): Mailbox
    {
        $user = $request->user();
        abort_unless($user->hasPermission(['admin', 'mail.use']), 403);

        return MailboxAccess::selected($user, $request->filled('mailbox_id') ? $request->integer('mailbox_id') : null) ?? abort(403);
    }

    /** A draft of this mailbox; anything else is not found. */
    private function ownDraft(MailMessage $message, Mailbox $mailbox): void
    {
        abort_unless($message->status === 'draft' && $message->sender_mailbox_id === $mailbox->id, 404);
    }

    /** @return array<string, mixed>|null the posted body, null when not posted */
    private function body(SaveDraftRequest $request): ?array
    {
        $raw = $request->validated('body_json');
        try {
            return is_string($raw) ? $this->guard->decode($raw) : null;
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['body_json' => $e->getMessage()]);
        }
    }
}
