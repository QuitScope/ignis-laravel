<?php

namespace Modules\Enotf\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Enotf\Support\LocalDateTimeFormatter;

/**
 * A department of a hospital POI with its availability. Nothing is mass-assignable.
 *
 * @property int $id
 * @property int $poi_id
 * @property string $name
 * @property int $sort_order
 * @property string $status
 * @property ?string $note
 * @property ?Carbon $status_updated_at
 * @property ?string $status_updated_by
 * @property Carbon $created_at
 */
class HospitalDepartment extends Model
{
    public const STATUSES = ['not_staffed', 'available', 'partially_available', 'full'];

    protected $table = 'enotf_hospital_departments';

    protected $guarded = ['*'];

    public const UPDATED_AT = null;

    protected $attributes = ['status' => 'not_staffed', 'sort_order' => 999];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'status_updated_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function setStatus(string $status, ?string $note, string $by): void
    {
        $this->forceFill(['status' => $status, 'note' => $note, 'status_updated_at' => now(), 'status_updated_by' => mb_substr($by, 0, 100)])->save();
    }

    /** @return array<string, mixed> */
    public function toView(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'sort_order' => $this->sort_order, 'status' => $this->status, 'note' => $this->note,
            'updated_at' => LocalDateTimeFormatter::at($this->status_updated_at), 'updated_by' => $this->status_updated_by,
        ];
    }
}
