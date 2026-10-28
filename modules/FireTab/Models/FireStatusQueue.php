<?php

namespace Modules\FireTab\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vehicle_id
 * @property string $vehicle_name
 * @property ?string $incident_number
 * @property string $status
 * @property Carbon $created_at
 * @property ?string $claim_token
 * @property ?Carbon $claimed_at
 * @property ?Carbon $delivered_at
 */
class FireStatusQueue extends Model
{
    protected $table = 'fire_status_queue';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'claimed_at' => 'datetime', 'delivered_at' => 'datetime'];
    }
}
