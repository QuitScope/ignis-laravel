<?php

namespace Modules\FireTab\Models;

use App\Models\Personnel;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $fire_incident_id
 * @property string $name
 * @property ?string $description
 * @property list<array{x: float, y: float}> $points
 * @property string $color
 * @property ?int $created_by_user_id
 * @property ?int $personnel_id
 * @property ?int $vehicle_id
 * @property bool $is_valid
 * @property ?Carbon $created_at
 */
class FireMapZone extends Model
{
    /** The 9 colours of the legacy form; nothing else reaches a style attribute. */
    public const COLORS = ['#dc3545', '#fd7e14', '#ffc107', '#198754', '#0dcaf0', '#0d6efd', '#6610f2', '#d63384', '#6c757d'];

    protected $table = 'fire_map_zones';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['points' => 'array', 'is_valid' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<Personnel, $this> */
    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
