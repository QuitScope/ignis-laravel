<?php

namespace Modules\ManvBoard\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Action log row: account FK plus a server-set name snapshot (never a name from the browser).
 *
 * @property int $id
 * @property int $manv_incident_id
 * @property Carbon $occurred_at
 * @property string $action
 * @property string $description
 * @property ?int $user_id
 * @property ?string $user_name
 * @property ?string $subject_type
 * @property ?int $subject_id
 */
class ManvLogEntry extends Model
{
    public $timestamps = false;

    protected $table = 'manv_log';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /** @return BelongsTo<ManvIncident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(ManvIncident::class, 'manv_incident_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
