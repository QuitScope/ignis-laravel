<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $task
 * @property Carbon $started_at
 * @property ?Carbon $finished_at
 * @property string $status
 * @property ?int $duration_ms
 * @property ?string $output
 */
class ScheduledTaskRun extends Model
{
    use Prunable;

    public const KEEP_DAYS = 30;

    public const KEEP_PER_TASK = 10;

    public $timestamps = false;

    protected $fillable = ['task', 'started_at', 'finished_at', 'status', 'duration_ms', 'output'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        $newest = DB::query()->fromSub(
            static::query()->selectRaw('id, row_number() over (partition by task order by started_at desc, id desc) as rn'),
            'ranked',
        )->select('id')->where('rn', '<=', self::KEEP_PER_TASK);

        return static::query()->where('started_at', '<', now()->subDays(self::KEEP_DAYS))->whereNotIn('id', $newest);
    }
}
