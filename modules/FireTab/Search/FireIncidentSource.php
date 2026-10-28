<?php

namespace Modules\FireTab\Search;

use App\Models\User;
use App\Modules\BelongsToModule;
use App\Search\SearchSource;
use App\Support\SqlLike;
use Illuminate\Support\Facades\Gate;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Support\OrganizationTime;

/** Incident reports in the global search: QM only (the crew finds its incidents on the tablet list). */
final class FireIncidentSource implements BelongsToModule, SearchSource
{
    public function module(): string
    {
        return 'firetab';
    }

    public function key(): string
    {
        return 'fire-incidents';
    }

    public function label(): string
    {
        return __('firetab.search.source');
    }

    public function allowed(User $user): bool
    {
        return Gate::forUser($user)->allows('viewQm', FireIncident::class);
    }

    public function search(User $user, string $q, int $limit): array
    {
        $query = FireIncident::query()->select(['id', 'number', 'location', 'keyword', 'started_at']);
        SqlLike::anyOf($query, ['number', 'location', 'keyword'], $q);

        return $query->orderByDesc('started_at')->orderByDesc('id')->limit($limit)->get()->map(fn (FireIncident $i) => [
            'label' => trim(($i->number ?? '').' '.($i->keyword ?? '')) ?: '#'.$i->id,
            'sub' => implode(' · ', array_filter([$i->location, OrganizationTime::display($i->started_at)])),
            'href' => "/firetab/qm/incidents/{$i->id}",
        ])->all();
    }

    public function vocabulary(): ?array
    {
        return null;
    }

    public function fuzzyAllowed(User $user): bool
    {
        return false;
    }
}
