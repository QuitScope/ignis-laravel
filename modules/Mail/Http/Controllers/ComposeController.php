<?php

namespace Modules\Mail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Mail\Content\MailContentValidator;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailMessage;
use Modules\Mail\Support\AddressRules;
use Modules\Mail\Support\AttachmentStorage;
use Modules\Mail\Support\HeaderVisibility;
use Modules\Mail\Support\MailboxAccess;
use Modules\Mail\Support\ReplyBuilder;
use Modules\Mail\Support\SendLimits;
use Modules\Mail\Support\SignatureTemplate;

/**
 * The compose form (legacy composeNew/Reply/ReplyAll/Forward/Draft). Opening it writes nothing: the draft is created
 * by the first autosave (POST /mail/drafts with in_reply_to / forward_from; the server takes the thread from there).
 * `{message}` is bound through MailDelivery::visibleTo; reply and forward additionally need a SENT mail with a copy in the
 * chosen mailbox, a draft must be this mailbox's own. Everything else is a 404, for admins and super admins too.
 */
class ComposeController extends Controller
{
    /** GET /mail/compose (?to= prefills one address) */
    public function create(Request $request): Response|RedirectResponse
    {
        [$user, $mailbox] = $this->context($request);
        if ($mailbox === null) {
            return redirect('/mail');
        }
        $to = $request->query('to');
        $to = is_string($to) && $to !== '' && strlen($to) <= SendLimits::ADDRESS_MAX ? [AddressRules::normalize($to)] : [];

        return $this->page($user, $mailbox, 'new', ['recipients' => ['to' => $to, 'cc' => [], 'bcc' => []]]);
    }

    /** GET /mail/compose/{reply|reply-all|forward}/{message} */
    public function original(Request $request, string $mode, MailMessage $message): Response|RedirectResponse
    {
        [$user, $mailbox] = $this->context($request);
        if ($mailbox === null) {
            return redirect('/mail');
        }
        abort_unless($message->status === 'sent' && $message->deliveries()->visibleTo($user)->where('mailbox_id', $mailbox->id)->exists(), 404);

        $draft = match ($mode) {
            'reply' => ReplyBuilder::reply($message, $mailbox),
            'reply-all' => ReplyBuilder::replyAll($message, $mailbox),
            default => ReplyBuilder::forward($message),
        };
        $forward = $mode === 'forward';

        return $this->page($user, $mailbox, $mode, [
            'subject' => $draft['subject'],
            'recipients' => ['to' => $draft['to'], 'cc' => $draft['cc'], 'bcc' => []],
            'body' => $draft['body'],
            'inReplyTo' => $forward ? null : $message->id,
            'forwardFrom' => $forward ? $message->id : null,
            'forwardNames' => $forward ? AttachmentStorage::forwardable($message)->map(fn (array $pair) => $pair[0]->original_name)->all() : [],
        ]);
    }

    /** GET /mail/compose/draft/{message}: an own draft of the chosen mailbox, as saved (no signature added). */
    public function draft(Request $request, MailMessage $message): Response|RedirectResponse
    {
        [$user, $mailbox] = $this->context($request);
        if ($mailbox === null) {
            return redirect('/mail');
        }
        abort_unless($message->status === 'draft' && $message->sender_mailbox_id === $mailbox->id, 404);
        $copy = $message->deliveries()->where('mailbox_id', $mailbox->id)->where('role', 'sender')->whereNull('deleted_at')->first() ?? abort(404);

        return $this->page($user, $mailbox, 'draft', [
            'draftId' => $message->id,
            'subject' => $message->subject,
            'recipients' => HeaderVisibility::for($copy->setRelation('message', $message)->setRelation('mailbox', $mailbox)),
            'body' => $message->body ?? ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
            'inReplyTo' => $message->in_reply_to,
            'attachments' => $message->attachments()->orderBy('id')->get()->map(AttachmentStorage::ref(...))->all(),
        ], signature: false);
    }

    /** @return array{User, ?Mailbox} */
    private function context(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasPermission(['admin', 'mail.use']), 403); // the page only; content follows MailDelivery::visibleTo
        $requested = $request->query('mailbox');
        $mailbox = MailboxAccess::selected($user, is_string($requested) && ctype_digit($requested) ? (int) $requested : null);
        if ($mailbox !== null && $requested !== null) {
            MailboxAccess::remember($mailbox);
        }

        return [$user, $mailbox];
    }

    /** @param array<string, mixed> $data */
    private function page(User $user, Mailbox $mailbox, string $mode, array $data, bool $signature = true): Response
    {
        return inertia('Mail/Compose', self::props($user, $mailbox, $mode, $data, $signature));
    }

    /**
     * The form's props (also of the workspace's compose dialog, InboxController).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function props(User $user, Mailbox $mailbox, string $mode, array $data, bool $signature = true): array
    {
        $data += [
            'draftId' => null, 'subject' => '', 'recipients' => ['to' => [], 'cc' => [], 'bcc' => []],
            'body' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
            'inReplyTo' => null, 'forwardFrom' => null, 'forwardNames' => [], 'attachments' => [],
        ];
        if ($signature) {
            $data['body'] = self::withSignature($data['body'], $user, $mailbox);
        }

        return $data + [
            'mode' => $mode,
            'mailbox' => ['id' => $mailbox->id, 'address' => $mailbox->address, 'display_name' => $mailbox->display_name, 'kind' => $mailbox->kind],
        ];
    }

    /**
     * The signature below the first (empty) line, separated by an empty line; before a following quote another empty
     * line. It is ordinary content the writer may edit or delete. Without text in the signature the body stays.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function withSignature(array $body, User $user, Mailbox $mailbox): array
    {
        $signature = MailContentValidator::normalize(SignatureTemplate::resolve(
            SignatureTemplate::forMailbox($mailbox), SignatureTemplate::writer($mailbox, $user), $mailbox->address, $mailbox->display_name,
        ));
        if (! SignatureTemplate::hasText($signature)) {
            return $body;
        }
        $rest = array_values((array) ($body['content'] ?? []));
        $first = array_shift($rest) ?? ['type' => 'paragraph'];
        $blank = ['type' => 'paragraph'];
        $body['content'] = [$first, $blank, ...$signature['content'], ...($rest === [] ? [] : [$blank, ...$rest])];

        return $body;
    }
}
