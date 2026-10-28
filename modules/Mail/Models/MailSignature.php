<?php

namespace Modules\Mail\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The signature of a mailbox; `mailbox_id` NULL is the default signature.
 *
 * @property int $id
 * @property ?int $mailbox_id
 * @property array<string, mixed> $body
 */
class MailSignature extends Model
{
    protected $table = 'mail_signatures';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['body' => 'array'];
    }
}
