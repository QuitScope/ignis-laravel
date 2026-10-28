<?php

namespace Modules\Federation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cached released eNOTF protocol of a peer. `data` holds the legacy fields 1:1, patient identity included.
 *
 * @property int $id
 * @property int $link_id
 * @property int $remote_id
 * @property ?string $number
 * @property ?Carbon $occurred_at
 * @property ?array<string, mixed> $data
 * @property ?Carbon $remote_updated_at
 */
class FederatedProtocol extends Model
{
    protected $table = 'federation_enotf_protocols';

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
