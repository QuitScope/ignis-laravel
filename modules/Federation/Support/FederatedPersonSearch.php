<?php

namespace Modules\Federation\Support;

use App\Support\SqlLike;
use Illuminate\Database\Eloquent\Builder;
use Modules\Federation\Models\FederatedPerson;

/**
 * Federation personnel for pickers of other modules (FireTab leader). Ids are `fed:{link}:{remote}`; names carry
 * the instance: "Name [Instanz]". Only active links with consume_personnel count. Callers check ModuleRegistry::active('federation') first.
 */
final class FederatedPersonSearch
{
    /** @return list<array{id: string, name: string, source: string}> */
    public static function search(string $q, int $limit): array
    {
        $query = self::people()->select(['federation_personnel.*']);
        SqlLike::anyOf($query, ['federation_personnel.full_name'], $q);

        return $query->orderBy('federation_personnel.full_name')->limit($limit)->get()
            ->map(fn (FederatedPerson $p) => ['id' => "fed:{$p->link_id}:{$p->remote_id}", 'name' => self::label($p), 'source' => $p->link->name])
            ->values()->all();
    }

    public static function resolve(string $id): ?string
    {
        if (preg_match('/^fed:(\d{1,18}):(\d{1,18})$/', $id, $m) !== 1) {
            return null;
        }
        $p = self::people()->select(['federation_personnel.*'])
            ->where('federation_personnel.link_id', (int) $m[1])->where('federation_personnel.remote_id', (int) $m[2])->first();

        return $p === null ? null : self::label($p);
    }

    /** @return Builder<FederatedPerson> */
    private static function people(): Builder
    {
        return FederatedPerson::query()->with('link:id,name')
            ->join('federation_links', 'federation_links.id', '=', 'federation_personnel.link_id')
            ->where('federation_links.is_active', true)->where('federation_links.consume_personnel', true);
    }

    private static function label(FederatedPerson $p): string
    {
        return mb_substr("{$p->full_name} [{$p->link->name}]", 0, 150);
    }
}
