<?php

namespace App\Search\Sources;

use App\Models\User;
use App\Models\VehicleDefect;
use App\Search\SearchSource;
use App\Support\SqlLike;
use Illuminate\Support\Facades\DB;

/** Defects by title, description or vehicle (vehicle.view); the target is the defect list of the vehicle (legacy). */
final class DefectSource implements SearchSource
{
    public function key(): string
    {
        return 'defects';
    }

    public function label(): string
    {
        return __('search.source.defects');
    }

    public function allowed(User $user): bool
    {
        return $user->can('vehicle.view');
    }

    public function search(User $user, string $q, int $limit): array
    {
        $query = DB::table('vehicle_defects as d')->join('vehicles as v', 'v.id', '=', 'd.vehicle_id')
            ->select('d.id', 'd.vehicle_id', 'd.title', 'd.status', 'v.name as vehicle_name');
        SqlLike::anyOf($query, ['d.title', 'd.description', 'v.name', 'v.identifier'], $q);

        return $query->orderByDesc('d.created_at')->orderByDesc('d.id')->limit($limit)->get()->map(fn (object $d) => [
            'label' => (string) $d->title,
            'sub' => $d->vehicle_name.' · '.(in_array($d->status, VehicleDefect::STATUSES, true) ? __("vehicles.defect_status.{$d->status}") : $d->status),
            'href' => "/vehicles/defects?vehicle={$d->vehicle_id}",
        ])->all();
    }

    public function vocabulary(): array
    {
        return VehicleDefect::query()->distinct()->limit(5000)->pluck('title')->all();
    }

    public function fuzzyAllowed(User $user): bool
    {
        return true;
    }
}
