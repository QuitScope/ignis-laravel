<?php

namespace Modules\Mail\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A fixed member mailbox of a static list.
 *
 * @property int $id
 * @property int $mail_list_id
 * @property int $mailbox_id
 */
class MailListMember extends Model
{
    public $timestamps = false;

    protected $table = 'mail_list_members';

    protected $guarded = ['*'];
}
