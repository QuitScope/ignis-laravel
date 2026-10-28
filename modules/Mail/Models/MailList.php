<?php

namespace Modules\Mail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A distribution list: static (fixed mailboxes) or dynamic (`rule` {role, rank, ems_qualification, fire_qualification,
 * specialty} => ID lists, OR-combined). `senders` managers: only mail.lists.manage may write to it.
 *
 * @property int $id
 * @property string $address
 * @property string $name
 * @property string $kind static|dynamic
 * @property ?array<string, list<int>> $rule
 * @property string $senders all|managers
 */
class MailList extends Model
{
    protected $table = 'mail_lists';

    protected $guarded = ['*'];

    protected $attributes = ['kind' => 'static', 'senders' => 'all'];

    protected function casts(): array
    {
        return ['rule' => 'array'];
    }

    /** @return BelongsToMany<Mailbox, $this> */
    public function mailboxes(): BelongsToMany
    {
        return $this->belongsToMany(Mailbox::class, 'mail_list_members', 'mail_list_id', 'mailbox_id');
    }
}
