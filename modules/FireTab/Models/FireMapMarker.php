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
 * @property string $marker_type
 * @property float $pos_x
 * @property float $pos_y
 * @property ?string $description
 * @property ?int $created_by_user_id
 * @property ?int $personnel_id
 * @property ?int $vehicle_id
 * @property ?Carbon $created_at
 */
class FireMapMarker extends Model
{
    public const TYPES = ['Einsatzort', 'Einsatzleiter', 'Bereitstellungsraum', 'Verletzte Person', 'Vermisste Person', 'custom'];

    public const LOCATION = 'Einsatzort';

    protected $table = 'fire_map_markers';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['pos_x' => 'float', 'pos_y' => 'float'];
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
