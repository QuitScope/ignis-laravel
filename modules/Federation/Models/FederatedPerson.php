<?php

namespace Modules\Federation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cached personnel of a peer (display data only).
 *
 * @property int $id
 * @property int $link_id
 * @property int $remote_id
 * @property string $full_name
 * @property ?string $service_number
 * @property ?string $rank
 * @property ?array<string, mixed> $qualifications
 * @property ?string $seen_run
 */
class FederatedPerson extends Model
{
    protected $table = 'federation_personnel';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['qualifications' => 'array'];
    }

    /** @return BelongsTo<FederationLink, $this> */
    public function link(): BelongsTo
    {
        return $this->belongsTo(FederationLink::class, 'link_id');
    }
}
