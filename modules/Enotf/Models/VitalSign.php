<?php

namespace Modules\Enotf\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Enotf\Support\VitalparameterCatalog;

/**
 * One measured vital sign of a protocol (table enotf_vitals). Server-set only (forceFill); soft delete by hand
 * (deleted_at/deleted_by_user_id), never a hard delete. Blood sugar is stored in mg/dl.
 *
 * @property int $id
 * @property int $enotf_protocol_id
 * @property string $parameter
 * @property ?string $value
 * @property ?string $value_text
 * @property ?string $unit
 * @property Carbon $measured_at
 * @property ?int $created_by_user_id
 * @property ?int $personnel_id
 * @property ?Carbon $deleted_at
 * @property ?int $deleted_by_user_id
 * @property ?array<string, mixed> $legacy
 */
class VitalSign extends Model
{
    protected $table = 'enotf_vitals';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['measured_at' => 'datetime', 'deleted_at' => 'datetime', 'value' => 'decimal:2', 'legacy' => 'array'];
    }

    /** @param Builder<VitalSign> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('deleted_at');
    }

    /**
     * Client/print shape: the display value already in the configured BZ unit, times in the app timezone.
     *
     * @return array{id: int, parameter: string, value: ?float, text: ?string, display: string, unit: string, measured_at: string}
     */
    public function toView(string $bzUnit): array
    {
        $value = $this->value === null ? null : (float) $this->value;
        $unit = (string) $this->unit;
        if ($this->parameter === 'bz' && $value !== null) {
            $value = VitalparameterCatalog::bzFromMgdl($value, $bzUnit);
            $unit = $bzUnit;
        }

        return [
            'id' => $this->id,
            'parameter' => $this->parameter,
            'value' => $value,
            'text' => $this->value_text,
            'display' => $value === null ? (string) $this->value_text : VitalparameterCatalog::format($value),
            'unit' => $unit,
            'measured_at' => $this->measured_at->setTimezone((string) config('ignis.timezone'))->format('Y-m-d H:i'),
        ];
    }
}
