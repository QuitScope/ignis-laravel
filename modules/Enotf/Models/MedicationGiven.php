<?php

namespace Modules\Enotf\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One administration of a medication (legacy medis JSON entry {wirkstoff, zeit, applikation, dosierung, einheit}).
 * Server-set only; soft delete by hand (deleted_at/deleted_by_user_id).
 *
 * @property int $id
 * @property int $enotf_protocol_id
 * @property ?int $medication_id
 * @property string $name
 * @property ?string $dose
 * @property ?string $unit
 * @property ?string $route
 * @property Carbon $given_at
 * @property ?Carbon $deleted_at
 * @property ?int $deleted_by_user_id
 * @property ?array<string, mixed> $legacy
 */
class MedicationGiven extends Model
{
    public const ROUTES = ['i.v.', 'i.o.', 'i.m.', 's.c.', 's.l.', 'p.o.'];

    public const UNITS = ['mcg' => 'µg', 'mg' => 'mg', 'g' => 'g', 'ml' => 'ml', 'IE' => 'IE'];

    protected $table = 'enotf_medications_given';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['given_at' => 'datetime', 'deleted_at' => 'datetime', 'legacy' => 'array'];
    }

    /** @param Builder<MedicationGiven> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('deleted_at');
    }

    /**
     * Given medication of a protocol (page, print, plausibility `medis`): active rows, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function listFor(EnotfProtocol $protocol): array
    {
        return self::query()->where('enotf_protocol_id', $protocol->id)->active()
            ->orderByDesc('given_at')->orderByDesc('id')->get()->map(fn (MedicationGiven $m) => $m->toView())->all();
    }

    /** @return array{id: int, medication_id: ?int, name: string, dose: ?string, unit: ?string, route: ?string, given_at: string} */
    public function toView(): array
    {
        return [
            'id' => $this->id,
            'medication_id' => $this->medication_id,
            'name' => $this->name,
            'dose' => $this->dose,
            'unit' => $this->unit === null ? null : (self::UNITS[$this->unit] ?? $this->unit),
            'route' => $this->route,
            'given_at' => $this->given_at->setTimezone((string) config('ignis.timezone'))->format('Y-m-d H:i'),
        ];
    }
}
