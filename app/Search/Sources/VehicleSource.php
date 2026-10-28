<?php

namespace App\Search\Sources;

use App\Models\User;
use App\Models\Vehicle;
use App\Search\SearchSource;
use App\Support\SqlLike;

/** Vehicles by identifier, radio name or licence plate (vehicle.view). */
final class VehicleSource implements SearchSource
{
    public function key(): string
    {
        return 'vehicles';
    }

    public function label(): string
    {
        return __('search.source.vehicles');
    }

    public function allowed(User $user): bool
    {
        return $user->can('vehicle.view');
    }

    public function search(User $user, string $q, int $limit): array
    {
        $query = Vehicle::query()->select(['id', 'identifier', 'name', 'license_plate']);
        SqlLike::anyOf($query, ['identifier', 'name', 'license_plate'], $q);

        return $query->orderBy('identifier')->orderBy('id')->limit($limit)->get()->map(fn (Vehicle $v) => [
            'label' => $v->name,
            'sub' => implode(' · ', array_filter([$v->identifier, (string) $v->license_plate], fn ($s) => $s !== '')),
            'href' => "/vehicles/{$v->id}",
        ])->all();
    }

    /** The radio name only, never identifier or plate. */
    public function vocabulary(): array
    {
        return Vehicle::query()->distinct()->limit(5000)->pluck('name')->all();
    }

    public function fuzzyAllowed(User $user): bool
    {
        return true;
    }
}
