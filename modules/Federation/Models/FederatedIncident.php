<?php

namespace Modules\Federation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cached fire incident of a peer. `data` holds the legacy fields 1:1, owner / caller contact included.
 *
 * @property int $id
 * @property int $link_id
 * @property int $remote_id
 * @property ?string $number
 * @property ?string $keyword
 * @property ?string $location
 * @property ?string $status
 * @property ?Carbon $occurred_at
 * @property ?array<string, mixed> $data
 * @property ?Carbon $remote_updated_at
 */
class FederatedIncident extends Model
{
    protected $table = 'federation_fire_incidents';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'data' => 'array', 'remote_updated_at' => 'datetime'];
    }

    /** @return BelongsTo<FederationLink, $this> */
    public function link(): BelongsTo
    {
        return $this->belongsTo(FederationLink::class, 'link_id');
    }
}
