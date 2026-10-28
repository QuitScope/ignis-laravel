<?php

namespace App\Search\Sources;

use App\Models\Personnel;
use App\Models\User;
use App\Search\SearchSource;
use App\Support\SqlLike;

/** Personnel by name or service number (PersonnelPolicy::viewList sees every record, archived ones too). */
final class PersonnelSource implements SearchSource
{
    public function key(): string
    {
        return 'personnel';
    }

    public function label(): string
    {
        return __('search.source.personnel');
    }

    public function allowed(User $user): bool
    {
        return $user->can('viewList', Personnel::class);
    }

    public function search(User $user, string $q, int $limit): array
    {
        $query = Personnel::query()->select(['id', 'full_name', 'service_number']);
        SqlLike::anyOf($query, ['full_name', 'service_number'], $q);

        return $query->orderBy('full_name')->orderBy('id')->limit($limit)->get()->map(fn (Personnel $p) => [
            'label' => $p->full_name,
            'sub' => $p->service_number !== '' ? __('search.personnel_sub', ['number' => $p->service_number]) : '',
            'href' => "/personnel/{$p->id}",
        ])->all();
    }

    /** Names only, never the service number. */
    public function vocabulary(): array
    {
        return Personnel::query()->distinct()->limit(5000)->pluck('full_name')->all();
    }

    public function fuzzyAllowed(User $user): bool
    {
        return true;
    }
}
