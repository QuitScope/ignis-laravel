<?php

namespace App\Notifications;

use App\Discord\DiscordChannel;
use App\Discord\DmRecipients;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * One inbox entry (legacy NotificationManager::notify): the row's `type` is the legacy kind key, `data` is
 * {title, message, link}. An unknown kind or an empty title is a programming error and throws; a link that is not
 * an internal path is dropped (logged), so nothing stored can become an open redirect.
 */
class InboxNotification extends Notification
{
    public const LINK_MAX = 512;

    private string $title;

    private ?string $link;

    public function __construct(private string $type, string $title, private ?string $message, ?string $link)
    {
        if (! app(NotificationKinds::class)->has($type)) {
            throw new InvalidArgumentException("Unknown notification kind: {$type}");
        }
        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException("Notification of kind {$type} without title");
        }

        $this->title = mb_substr($title, 0, 255);
        $this->message = $message === '' ? null : $message;
        $this->link = self::safeLink($link);
        if ($this->link === null && $link !== null && $link !== '') {
            Log::warning("Notification link dropped (not an internal path): {$type}");
        }
    }

    /** $link if it is an internal path of at most 512 characters (`/x`, not `//x`, `/\x`, no whitespace, control, format or separator characters; invalid UTF-8 is refused), else null. */
    public static function safeLink(?string $link): ?string
    {
        return $link !== null && strlen($link) <= self::LINK_MAX && preg_match('#^/(?![/\\\\])[^\s\p{C}\p{Z}\\\\]*$#Du', $link) === 1
            ? $link : null;
    }

    /**
     * The inbox always (synchronously, this class is not queued); a Discord DM on top when the DM rule says yes. The
     * database channel comes first, so the inbox row exists before the DM job is queued.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $dm = $notifiable instanceof User && rescue(fn () => app(DmRecipients::class)->for($notifiable, $this->type) !== null, false);

        return $dm ? ['database', DiscordChannel::class] : ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->type;
    }

    /** @return array{title: string, message: ?string, link: ?string} */
    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'message' => $this->message, 'link' => $this->link];
    }
}
