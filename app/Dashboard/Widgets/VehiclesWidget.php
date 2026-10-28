<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Models\User;
use App\Models\Vehicle;

/**
 * The vehicles block (legacy vehicles.php): a tick scale (one tick per vehicle with a status, at most 40, `on` =
 * share of ready ones) and the five most recently changed active vehicles with the derived operability, the station,
 * the service (glyph) and the instant of the last status (`since`, the client shows the time elapsed).
 */
final class VehiclesWidget implements Widget
{
    public const MAX_TICKS = 40;

    public const ROWS = 5;

    public function key(): string
    {
        return 'vehicles';
    }

    public function area(): string
    {
        return 'main';
    }

    public function order(): int
    {
        return 20;
    }

    public function allowed(User $user): bool
    {
        return $user->can('vehicle.view');
    }

    public function data(User $user): ?array
    {
        $withStatus = fn () => Vehicle::active()->whereNotNull('fms_status')->where('fms_status', '<>', '');
        $total = $withStatus()->count();
        if ($total === 0) {
            return null;
        }
        $ready = $withStatus()->whereIn('fms_status', ['1', '2'])->count();
        $ticks = min($total, self::MAX_TICKS);

        $rows = $withStatus()->withBlockingDefectsCount()->with('station:id,name')
            ->orderByDesc('fms_status_at')->orderByDesc('updated_at')->orderByDesc('id')->limit(self::ROWS)->get();

        return [
            'total' => $total,
            'ready' => $ready,
            'ticks' => $ticks,
            'on' => (int) round($ready / $total * $ticks),
            'rows' => $rows->map(fn (Vehicle $v) => [
                'id' => $v->id,
                'name' => $v->name,
                'fms_status' => $v->fms_status,
                'fms_label' => Vehicle::fmsLabel($v->fms_status),
                'since' => $v->fms_status_at?->toIso8601String(),
                'service' => $v->service,
                'state' => $v->operabilityState(),
                'blocking' => $v->blocking_defects_count,
                'station' => $v->station?->name,
                'href' => "/vehicles/{$v->id}",
            ])->all(),
        ];
    }
}
