<?php

namespace Modules\Enotf\Models;

use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The crew of a vehicle. Open while ended_at is null; life cycle in CrewService.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property ?int $user_id
 * @property Carbon $started_at
 * @property ?Carbon $ended_at
 * @property ?string $ended_reason
 * @property ?string $pin_hash Hash::make of the session PIN, null without PIN
 * @property int $failed_attempts
 * @property ?Carbon $locked_at locked while set
 * @property ?Carbon $last_activity_at
 */
class EnotfCrew extends Model
{
    protected $table = 'enotf_crews';

    protected $guarded = ['*'];

    public $timestamps = false;

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'locked_at' => 'datetime', 'last_activity_at' => 'datetime', 'failed_attempts' => 'integer'];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return HasMany<CrewMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(CrewMember::class, 'enotf_crew_id');
    }
}
