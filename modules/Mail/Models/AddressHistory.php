<?php

namespace Modules\Mail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A former address of a mailbox: reserved for it and an alias of it.
 *
 * @property int $id
 * @property string $address
 * @property int $mailbox_id
 * @property ?Carbon $released_at
 */
class AddressHistory extends Model
{
    public $timestamps = false;

    protected $table = 'mail_address_history';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['released_at' => 'datetime'];
    }
}
