<?php

namespace Modules\Federation\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One sync run of one type for one link: codes and counters only. Kept 30 days: model:prune scans only app/Models, so federation:prune passes --model for this class.
 *
 * @property int $id
 * @property int $link_id
 * @property string $type
 * @property string $status
 * @property int $records
 * @property int $duration_ms
 * @property ?string $error
 * @property ?Carbon $created_at
 */
class SyncRun extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $table = 'federation_sync_runs';

    protected $guarded = ['*'];

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(30));
    }
}
