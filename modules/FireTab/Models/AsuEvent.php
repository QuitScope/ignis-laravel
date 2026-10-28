<?php

namespace Modules\FireTab\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\FireTab\Enums\AsuEventType;

/**
 * Immutable; wrong entries are voided, never deleted.
 *
 * @property int $id
 * @property int $fire_incident_id
 * @property int $trupp_id
 * @property ?int $member_id
 * @property AsuEventType $type
 * @property ?int $bar
 * @property ?string $note
 * @property string $client_uuid
 * @property ?string $device
 * @property Carbon $occurred_at
 * @property Carbon $received_at
 * @property ?Carbon $voided_at
 * @property ?int $voided_by
 */
class AsuEvent extends Model
{
    protected $table = 'fire_asu_events';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['type' => AsuEventType::class, 'occurred_at' => 'datetime', 'received_at' => 'datetime', 'voided_at' => 'datetime'];
    }
}
