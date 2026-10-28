<?php

namespace Modules\Mail\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An account that reads and writes a group mailbox.
 *
 * @property int $id
 * @property int $mailbox_id
 * @property int $user_id
 */
class MailboxMember extends Model
{
    protected $table = 'mail_mailbox_members';

    protected $guarded = ['*'];
}
