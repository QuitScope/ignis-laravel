<?php

namespace Modules\Enotf\Search;

use App\Models\User;
use App\Modules\BelongsToModule;
use App\Search\SearchSource;
use App\Support\SqlLike;
use Illuminate\Support\Facades\Gate;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Support\LocalDateTimeFormatter;

/** Protocols in the global search: QM only, hidden ones left out. A hit shows ENR, vehicle and time, never patient data. */
final class ProtocolSource implements BelongsToModule, SearchSource
{
    public function module(): string
    {
        return 'enotf';
    }

    public function key(): string
    {
        return 'enotf-protocols';
    }

    public function label(): string
    {
        return __('enotf.search.source');
    }

    public function allowed(User $user): bool
    {
        return Gate::forUser($user)->allows('viewQm', EnotfProtocol::class);
    }

    public function search(User $user, string $q, int $limit): array
    {
        $query = EnotfProtocol::query()->select(['id', 'enr', 'created_at', 'vehicle_transport_label', 'vehicle_na_label'])->whereNull('hidden_at');
        SqlLike::anyOf($query, ['enr', 'vehicle_transport_label', 'vehicle_na_label'], $q);

        return $query->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->map(fn (EnotfProtocol $p) => [
            'label' => $p->enr,
            'sub' => implode(' · ', array_filter([$p->vehicle_transport_label ?: $p->vehicle_na_label, LocalDateTimeFormatter::at($p->created_at)])),
            'href' => '/enotf/p/'.rawurlencode($p->enr),
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
