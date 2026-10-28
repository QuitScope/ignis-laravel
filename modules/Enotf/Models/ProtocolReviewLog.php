<?php

namespace Modules\Enotf\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One QM log entry: kind status (from/to qm_status), comment (plain text) or reopen. Nothing is mass-assignable.
 *
 * @property int $id
 * @property int $enotf_protocol_id
 * @property string $kind status|comment|reopen
 * @property ?int $from_status
 * @property ?int $to_status
 * @property ?string $text
 * @property ?int $actor_user_id
 * @property string $actor_name
 * @property Carbon $created_at
 */
class ProtocolReviewLog extends Model
{
    protected $table = 'enotf_review_log';

    protected $guarded = ['*'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['from_status' => 'integer', 'to_status' => 'integer', 'created_at' => 'datetime'];
    }
}
