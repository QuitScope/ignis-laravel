<?php

namespace Modules\Mail\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Mail\Support\MailboxAccess;

/**
 * One copy of a message in one mailbox, per role. `deleted_at` marks only this copy (a plain column, no SoftDeletes).
 *
 * @property int $id
 * @property int $message_id
 * @property int $mailbox_id
 * @property string $role sender|to|cc|bcc
 * @property string $folder inbox|sent|drafts|archive|trash
 * @property ?Carbon $read_at
 * @property bool $is_flagged
 * @property ?Carbon $deleted_at
 * @property ?Carbon $sorted_at
 * @property-read MailMessage $message
 * @property-read Mailbox $mailbox
 */
class MailDelivery extends Model
{
    public const ROLES = ['sender', 'to', 'cc', 'bcc'];

    public const FOLDERS = ['inbox', 'sent', 'drafts', 'archive', 'trash'];

    protected $table = 'mail_deliveries';

    protected $guarded = ['*'];

    protected $attributes = ['is_flagged' => false];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'is_flagged' => 'boolean', 'deleted_at' => 'datetime', 'sorted_at' => 'datetime'];
    }

    /**
     * THE access rule for mail content: copies in the mailboxes the account may open
     * (MailboxAccess::accessible), not deleted. No permission, no `admin`, no super admin widens it.
     *
     * @param  Builder<MailDelivery>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('mailbox_id', MailboxAccess::accessibleIds($user))->whereNull('deleted_at');
    }

    /** @return BelongsTo<MailMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class);
    }

    /** @return BelongsTo<Mailbox, $this> */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
