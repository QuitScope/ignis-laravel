<?php

namespace Modules\Enotf\Models;

use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A request to share a protocol with another vehicle's crew. Status changes only through ShareService
 * (conditional UPDATE). Nothing is mass-assignable.
 *
 * @property int $id
 * @property int $protocol_id
 * @property int $from_vehicle_id
 * @property int $to_vehicle_id
 * @property ?int $requested_by_user_id
 * @property string $status pending|accepted|rejected|cancelled|expired
 * @property array<string, mixed> $payload
 * @property ?int $target_protocol_id
 * @property Carbon $expires_at
 * @property ?Carbon $resolved_at
 * @property ?int $resolved_by_user_id
 */
class ShareRequest extends Model
{
    protected $table = 'enotf_share_requests';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'expires_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<EnotfProtocol, $this> */
    public function protocol(): BelongsTo
    {
        return $this->belongsTo(EnotfProtocol::class, 'protocol_id');
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function fromVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'from_vehicle_id');
    }

    /**
     * Pending and not expired (expiry is a read condition; enotf:share-expire marks the rest).
     *
     * @param  Builder<ShareRequest>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', 'pending')->where('expires_at', '>', now());
    }
}
