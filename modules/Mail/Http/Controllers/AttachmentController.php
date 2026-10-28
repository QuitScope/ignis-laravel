<?php

namespace Modules\Mail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Modules\Mail\Http\Middleware\MailNoStore;
use Modules\Mail\Models\Attachment;
use Modules\Mail\Models\MailMessage;
use Modules\Mail\Support\AttachmentRefused;
use Modules\Mail\Support\AttachmentStorage;
use Modules\Mail\Support\MailboxAccess;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Attachments (legacy uploadAttachment/downloadAttachment/deleteAttachment). `{message}` and `{mailAttachment}` are
 * bound through MailDelivery::visibleTo (MailServiceProvider): no Gate, no permission; an account without a copy that is
 * not deleted, in any mailbox it may open, gets a 404 (admin and super admin too). Uploading and deleting only on an
 * own draft of the chosen mailbox. A download is always `attachment`, never inline.
 */
class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentStorage $storage) {}

    /** POST /mail/drafts/{message}/attachments {file, mailbox_id?} */
    public function store(Request $request, MailMessage $message): JsonResponse
    {
        $this->ownDraft($request, $message);
        $request->validate(['file' => ['required', 'file']]);
        /** @var UploadedFile $file */
        $file = $request->file('file');

        try {
            $attachment = $this->storage->store($message, $file);
        } catch (AttachmentRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['attachment' => AttachmentStorage::ref($attachment)], 201);
    }

    /** DELETE /mail/attachments/{mailAttachment} {mailbox_id?}: only from an own draft. */
    public function destroy(Request $request, Attachment $mailAttachment): JsonResponse
    {
        $this->ownDraft($request, $mailAttachment->message);

        try {
            $this->storage->remove($mailAttachment);
        } catch (AttachmentRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['deleted' => true]);
    }

    /** GET /mail/attachments/{mailAttachment}: the file as a download (RFC 6266 name, nosniff, no-store). */
    public function show(Attachment $mailAttachment): BinaryFileResponse
    {
        $path = $this->storage->absolutePath($mailAttachment) ?? abort(404, '', MailNoStore::HEADERS);

        return response()->file($path, [
            'Content-Type' => $mailAttachment->mime,
            'Content-Disposition' => self::contentDisposition(AttachmentStorage::nameFor($mailAttachment->original_name, $mailAttachment->mime)),
            'X-Content-Type-Options' => 'nosniff',
        ] + MailNoStore::HEADERS);
    }

    /** Content-Disposition after RFC 6266/5987: an ASCII stand-in in `filename`, the full name in `filename*`. */
    public static function contentDisposition(string $name): string
    {
        $ascii = str_replace(['"', '\\'], '_', (string) preg_replace('/[^\x20-\x7E]/u', '_', $name));

        return 'attachment; filename="'.($ascii !== '' ? $ascii : 'attachment').'"; filename*=UTF-8\'\''.rawurlencode($name);
    }

    /** A draft of the chosen mailbox (`mailbox_id`, else the remembered one); a foreign mailbox 403, anything else 404. */
    private function ownDraft(Request $request, MailMessage $message): void
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasPermission(['admin', 'mail.use']), 403); // the page right only; content follows visibleTo
        $request->validate(['mailbox_id' => ['nullable', 'integer', 'min:1']]);
        $mailbox = MailboxAccess::selected($user, $request->filled('mailbox_id') ? $request->integer('mailbox_id') : null) ?? abort(403);
        abort_unless($message->status === 'draft' && $message->sender_mailbox_id === $mailbox->id, 404);
    }
}
