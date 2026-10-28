<?php

namespace Modules\Mail\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A mail (draft or sent). Never reached directly: every read goes through MailDelivery::visibleTo.
 * `header` holds the raw to/cc/bcc lists and is shown only through visibleHeader().
 *
 * @property int $id
 * @property int $sender_mailbox_id
 * @property ?int $sent_by_user_id
 * @property string $subject
 * @property ?array<string, mixed> $body
 * @property ?string $search_text
 * @property ?array<string, list<string>> $header
 * @property string $thread_id
 * @property ?int $in_reply_to
 * @property string $status draft|sent
 * @property ?Carbon $sent_at
 * @property-read ?Carbon $created_at
 * @property-read ?Carbon $updated_at
 */
class MailMessage extends Model
{
    protected $table = 'mail_messages';

    protected $guarded = ['*'];

    protected $attributes = ['status' => 'draft', 'subject' => ''];

    protected function casts(): array
    {
        return ['body' => 'array', 'header' => 'array', 'sent_at' => 'datetime'];
    }

    /** @return BelongsTo<Mailbox, $this> */
    public function senderMailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class, 'sender_mailbox_id');
    }

    /** @return BelongsTo<User, $this> */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    /** @return HasMany<MailDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(MailDelivery::class, 'message_id');
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'message_id');
    }
}
