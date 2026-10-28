<?php

namespace Modules\Federation\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Provider keyset paging (fixes legacy F1/F5): opaque base64url(`updated_at|id`) of the provider's own
 * values, rows ordered by (updated_at, id). `cursor` is the position after the page (the consumer stores it), `next`
 * is the same value only while more rows follow.
 */
final class PaginationCursor
{
    public const MAX_LIMIT = 200;

    public static function encode(?Carbon $updatedAt, int $id): string
    {
        return rtrim(strtr(base64_encode(($updatedAt?->copy()->utc()->format('Y-m-d H:i:s') ?? '').'|'.$id), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: int}|null updated_at ('' = null), id; null when malformed */
    public static function decode(string $cursor): ?array
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false || preg_match('/\A(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)?\|(\d{1,19})\z/', $raw, $m) !== 1) {
            return null;
        }

        return [$m[1], (int) $m[2]];
    }

    /**
     * The provider answer `{success, instance_id, data, cursor, next}`; $map turns a row into its explicit field list
     * (never toArray()). Invalid `after`/`limit` => 422 `bad_cursor`.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(TModel): array<string, mixed>  $map
     */
    public static function respond(Builder $query, Request $request, Closure $map): JsonResponse
    {
        $page = self::page($query, $request);
        if ($page === null) {
            return response()->json(['success' => false, 'error' => 'bad_cursor'], 422);
        }

        return response()->json([
            'success' => true, 'instance_id' => FederationInstance::instanceId(),
            'data' => array_map($map, $page['rows']), 'cursor' => $page['cursor'], 'next' => $page['next'],
        ]);
    }

    /**
     * One page of $query after the request's `after` cursor, `limit` 1-200 (100). Null when the parameters are invalid.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return array{rows: list<TModel>, cursor: ?string, next: ?string}|null
     */
    public static function page(Builder $query, Request $request): ?array
    {
        $limit = $request->query('limit', '100');
        $after = $request->query('after');
        if (! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > self::MAX_LIMIT || ($after !== null && ! is_string($after))) {
            return null;
        }
        $table = $query->getModel()->getTable();
        // Never hand out the still-open second: a later write in it with a smaller id would sort below the stored cursor
        // and be skipped (lost tombstones). Such rows come with the next poll. No overlap rewind (it loops on full pages).
        $query->where(fn (Builder $q) => $q->whereNull("$table.updated_at")->orWhere("$table.updated_at", '<', now()->startOfSecond()));
        if (is_string($after) && $after !== '') {
            $pos = self::decode($after);
            if ($pos === null) {
                return null;
            }
            [$at, $id] = $pos;
            // NULL updated_at sorts first (MySQL/SQLite): a null position only continues among nulls, then all dated rows.
            $at === ''
                ? $query->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull("$table.updated_at")->where("$table.id", '>', $id))->orWhereNotNull("$table.updated_at"))
                : $query->where(fn (Builder $q) => $q->where("$table.updated_at", '>', $at)->orWhere(fn (Builder $q) => $q->where("$table.updated_at", $at)->where("$table.id", '>', $id)));
        }
        $rows = $query->orderBy("$table.updated_at")->orderBy("$table.id")->limit((int) $limit + 1)->get()->all();
        $more = count($rows) > (int) $limit;
        $rows = array_slice($rows, 0, (int) $limit);
        $last = end($rows);
        /** @var ?Carbon $lastAt */
        $lastAt = $last === false ? null : $last->getAttribute('updated_at');
        $cursor = $last === false ? null : self::encode($lastAt, (int) $last->getKey());

        return ['rows' => $rows, 'cursor' => $cursor, 'next' => $more ? $cursor : null];
    }
}
