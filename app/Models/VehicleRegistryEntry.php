<?php

namespace App\Models;

use Database\Factories\VehicleRegistryEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A vehicle reported by the FiveM resource, waiting to be imported, merged or ignored.
 *
 * @property int $id
 * @property ?int $emd_vehicle_id
 * @property string $name
 * @property ?string $identifier
 * @property ?string $type
 * @property int $service Vehicle::SERVICE_*
 * @property ?string $department
 * @property ?string $value_long
 * @property ?string $job
 * @property ?string $image
 * @property ?string $radio_channel
 * @property ?array<string, mixed> $payload
 * @property string $status one of STATUSES
 * @property ?int $processed_by
 * @property ?Carbon $processed_at
 * @property-read ?Carbon $created_at
 */
class VehicleRegistryEntry extends Model
{
    /** @use HasFactory<VehicleRegistryEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const STATUSES = [self::PENDING, self::ACCEPTED, self::REJECTED];

    protected $fillable = [
        'emd_vehicle_id', 'name', 'identifier', 'type', 'service', 'department', 'value_long', 'job', 'image',
        'radio_channel', 'payload', 'status', 'processed_by', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['service' => 'integer', 'payload' => 'array', 'processed_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
