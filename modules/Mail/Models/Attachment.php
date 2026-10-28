<?php

namespace Modules\Mail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file of a message on a private disk; served only to participants (MailDelivery::visibleTo). `missing`: imported row
 * whose file was not found.
 *
 * @property int $id
 * @property int $message_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property bool $missing
 */
class Attachment extends Model
{
    protected $table = 'mail_attachments';

    protected $guarded = ['*'];

    protected $attributes = ['missing' => false];

    protected function casts(): array
    {
        return ['size' => 'integer', 'missing' => 'boolean'];
    }

    /** @return BelongsTo<MailMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class);
    }
}
