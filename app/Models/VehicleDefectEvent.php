<?php

namespace App\Models;

use Database\Factories\VehicleDefectEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry of a defect's history (created, updated, resolved ...).
 *
 * @property int $id
 * @property int $vehicle_defect_id
 * @property ?int $user_id null for crew without an account
 * @property string $action
 * @property ?string $details
 * @property-read ?Carbon $created_at
 */
class VehicleDefectEvent extends Model
{
    /** @use HasFactory<VehicleDefectEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['vehicle_defect_id', 'user_id', 'action', 'details'];

    /** @return BelongsTo<VehicleDefect, $this> */
    public function defect(): BelongsTo
    {
        return $this->belongsTo(VehicleDefect::class, 'vehicle_defect_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
