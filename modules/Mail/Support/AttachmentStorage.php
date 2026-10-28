<?php

namespace Modules\Mail\Support;

use Carbon\CarbonInterface;
use Closure;
use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Modules\Mail\Models\Attachment;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailMessage;
use Throwable;

/**
 * Mail attachments on the private disk `mail-attachments` (legacy src/AttachmentStorage.php), flat as
 * `mail-<message>-<16hex>.<ext>`, served only by AttachmentController to participants.
 *
 * The type comes from the magic bytes (finfo), never from the name or the browser: images, PDF, plain text; no SVG,
 * no HTML. The extension of the stored file and of the shown name follow that type. Limits: 5 MB per file, 10 MB per
 * mail, 200 MB per mailbox (every attachment of the mails it wrote). They are checked under the message row lock
 * and then the mailbox lock (the order of Mailer::send), so two uploads never pass a limit together and nothing is
 * attached once the mail went out: a sent mail's attachments never change.
 */
final class AttachmentStorage
{
    public const DISK = 'mail-attachments';

    public const MAX_FILE_BYTES = 5 * 1024 * 1024;

    public const MAX_MAIL_BYTES = 10 * 1024 * 1024;

    public const MAILBOX_QUOTA_BYTES = 200 * 1024 * 1024;

    public const ALLOWED = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
    ];

    public const FILE_PATTERN = '/^mail-\d+-[0-9a-f]{16}\.(png|jpg|webp|gif|pdf|txt)$/D';

    /**
     * Stores an upload on a draft.
     *
     * @throws AttachmentRefused
     */
    public function store(MailMessage $draft, UploadedFile $file): Attachment
    {
        $tmp = $file->getRealPath();
        if (! $file->isValid() || $tmp === false) {
            throw new AttachmentRefused(__('mail.attachment.failed'));
        }
        $size = (int) @filesize($tmp);
        if ($size > self::MAX_FILE_BYTES) {
            throw new AttachmentRefused(__('mail.attachment.too_large', ['max' => self::MAX_FILE_BYTES >> 20]));
        }
        $mime = @(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $extension = is_string($mime) ? (self::ALLOWED[$mime] ?? null) : null;
        if ($extension === null) {
            throw new AttachmentRefused(__('mail.attachment.type'));
        }
        $name = self::nameFor(self::sanitizeName($file->getClientOriginalName()), $mime);

        return $this->underLock($draft, function (MailMessage $message, int $mailTotal, int $boxTotal) use ($file, $size, $mime, $extension, $name) {
            $this->checkRoom($mailTotal, $boxTotal, $size);
            $path = self::fileName($message->id, $extension);
            if ($this->disk()->putFileAs('', $file, $path) === false) {
                throw new AttachmentRefused(__('mail.attachment.failed'));
            }

            return $this->insert($message, $path, $name, $mime, $size);
        });
    }

    /**
     * Forwarding: copies the attachments of $from onto the draft $to as new files (deleting one never takes the
     * other), as far as they fit into 10 MB per mail and the mailbox quota. Missing files are skipped.
     *
     * @return list<Attachment>
     */
    public function copyAll(MailMessage $from, MailMessage $to): array
    {
        $sources = self::forwardable($from);
        if ($sources->isEmpty()) {
            return [];
        }

        return $this->underLock($to, function (MailMessage $message, int $mailTotal, int $boxTotal) use ($sources) {
            File::ensureDirectoryExists($this->disk()->path(''));
            $copies = [];
            try {
                foreach ($sources as [$attachment, $source]) {
                    if ($mailTotal + $attachment->size > self::MAX_MAIL_BYTES || $boxTotal + $attachment->size > self::MAILBOX_QUOTA_BYTES) {
                        continue;
                    }
                    $path = self::fileName($message->id, self::ALLOWED[$attachment->mime]);
                    if (! @copy($source, $this->disk()->path($path))) {
                        continue;
                    }
                    $copies[] = $this->insert($message, $path, $attachment->original_name, $attachment->mime, $attachment->size);
                    $mailTotal += $attachment->size;
                    $boxTotal += $attachment->size;
                }
            } catch (Throwable $e) {
                foreach ($copies as $copy) {
                    $this->disk()->delete($copy->path);
                }
                throw $e;
            }

            return $copies;
        });
    }

    /**
     * What a forward of $message takes along: files that exist, of an allowed type, up to 10 MB together, by id.
     *
     * @return Collection<int, array{Attachment, string}> [row, absolute path]
     */
    public static function forwardable(MailMessage $message): Collection
    {
        $storage = app(self::class);
        $total = 0;
        $out = [];
        foreach ($message->attachments()->orderBy('id')->get() as $a) {
            $path = isset(self::ALLOWED[$a->mime]) ? $storage->absolutePath($a) : null;
            if ($path !== null && $total + $a->size <= self::MAX_MAIL_BYTES) {
                $out[] = [$a, $path];
                $total += $a->size;
            }
        }

        return collect($out);
    }

    /**
     * Removes an attachment of a draft (row under the message lock, then the file).
     *
     * @throws AttachmentRefused when the mail was sent meanwhile
     */
    public function remove(Attachment $attachment): void
    {
        $path = $this->absolutePath($attachment);
        $this->underLock($attachment->message, fn () => Attachment::query()->whereKey($attachment->id)->delete());
        $path === null || @unlink($path);
    }

    /**
     * Deletes a DRAFT with its copy and attachment rows (cascade), then the files. Under the message lock with the
     * status re-checked (a send committing meanwhile must never lose a delivered mail); with $untouchedBefore also only
     * when it was not saved since. Returns false and changes nothing when the row is no longer such a draft.
     */
    public function purge(MailMessage $message, ?CarbonInterface $untouchedBefore = null): bool
    {
        $paths = DB::transaction(function () use ($message, $untouchedBefore) {
            $draft = MailMessage::query()->whereKey($message->id)->lockForUpdate()->first();
            if ($draft === null || $draft->status !== 'draft' || ($untouchedBefore !== null && ! $draft->updated_at?->lt($untouchedBefore))) {
                return null;
            }
            $paths = $draft->attachments()->get()->map(fn (Attachment $a) => $this->absolutePath($a))->filter();
            $draft->delete();

            return $paths;
        }, Mailer::ATTEMPTS);
        $paths?->each(fn (string $p) => @unlink($p));

        return $paths !== null;
    }

    public function delete(Attachment $attachment): void
    {
        $path = $this->absolutePath($attachment);
        $attachment->delete();
        $path === null || @unlink($path);
    }

    /** The file on the disk, or null when missing, marked missing or outside the disk root (realpath, no symlink out). */
    public function absolutePath(Attachment $attachment): ?string
    {
        if ($attachment->missing || $attachment->disk !== self::DISK || str_contains($attachment->path, "\0")) {
            return null;
        }
        $root = $this->root();
        $file = realpath($this->disk()->path(basename($attachment->path)));
        if ($root === null || $file === false || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file)) {
            return null;
        }

        return $file;
    }

    public function root(): ?string
    {
        $root = realpath($this->disk()->path(''));

        return $root === false ? null : $root;
    }

    /** @return array{id: int, name: string, size: int} */
    public static function ref(Attachment $attachment): array
    {
        return ['id' => $attachment->id, 'name' => $attachment->original_name, 'size' => $attachment->size];
    }

    /**
     * The extension follows the detected type, not the name: a "Wachplan.bat" recognized as text goes out as
     * "Wachplan.txt", a missing extension is added. On upload and again on download.
     */
    public static function nameFor(string $name, string $mime): string
    {
        $extension = self::ALLOWED[$mime] ?? null;
        if ($extension === null) {
            return $name;
        }
        [$stem, $current] = preg_match('/^(.*)\.([A-Za-z0-9]{1,10})$/s', $name, $m) === 1 ? [$m[1], strtolower($m[2])] : [$name, ''];
        if ($current === $extension || ($extension === 'jpg' && $current === 'jpeg')) {
            return $name;
        }

        return ($stem !== '' ? $stem : __('mail.attachment.fallback_name')).'.'.$extension;
    }

    /**
     * Control characters (C0/C1; CR/LF would end a header), format characters (bidi overrides like U+202E that fake an
     * extension) and path separators out, valid UTF-8, at most 200 characters.
     */
    public static function sanitizeName(string $name): string
    {
        $name = trim((string) preg_replace('/[\p{Cc}\p{Cf}\/\\\\]/u', '', mb_scrub($name, 'UTF-8')));
        $name = mb_substr($name, 0, 200);

        return $name !== '' ? $name : __('mail.attachment.fallback_name');
    }

    /**
     * Runs $fn with the draft row and then its mailbox locked (Mailer::send's order) and the bytes already on the mail
     * and on the mailbox.
     *
     * @template T
     *
     * @param  Closure(MailMessage, int, int): T  $fn
     * @return T
     *
     * @throws AttachmentRefused
     */
    private function underLock(MailMessage $draft, Closure $fn): mixed
    {
        return DB::transaction(function () use ($draft, $fn) {
            $message = MailMessage::query()->whereKey($draft->id)->lockForUpdate()->first();
            if ($message === null) {
                throw new AttachmentRefused(__('mail.attachment.not_found'), 404);
            }
            if ($message->status !== 'draft') {
                throw new AttachmentRefused(__('mail.attachment.sent'), 409);
            }
            Mailbox::query()->whereKey($message->sender_mailbox_id)->lockForUpdate()->first();
            $mailTotal = (int) Attachment::query()->where('message_id', $message->id)->sum('size');
            $boxTotal = (int) Attachment::query()->whereIn('message_id', MailMessage::query()->select('id')->where('sender_mailbox_id', $message->sender_mailbox_id))->sum('size');

            return $fn($message, $mailTotal, $boxTotal);
        }, Mailer::ATTEMPTS);
    }

    /** @throws AttachmentRefused */
    private function checkRoom(int $mailTotal, int $boxTotal, int $size): void
    {
        if ($mailTotal + $size > self::MAX_MAIL_BYTES) {
            throw new AttachmentRefused(__('mail.attachment.mail_full', ['max' => self::MAX_MAIL_BYTES >> 20]));
        }
        if ($boxTotal + $size > self::MAILBOX_QUOTA_BYTES) {
            throw new AttachmentRefused(__('mail.attachment.quota', ['max' => self::MAILBOX_QUOTA_BYTES >> 20]));
        }
    }

    private function insert(MailMessage $message, string $path, string $name, string $mime, int $size): Attachment
    {
        try {
            return Attachment::query()->forceCreate([
                'message_id' => $message->id, 'disk' => self::DISK, 'path' => $path, 'original_name' => $name, 'mime' => $mime, 'size' => $size,
            ]);
        } catch (Throwable $e) {
            $this->disk()->delete($path);
            throw $e;
        }
    }

    public static function fileName(int $messageId, string $extension): string
    {
        return sprintf('mail-%d-%s.%s', $messageId, bin2hex(random_bytes(8)), $extension);
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
