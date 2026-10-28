<?php

namespace App\Documents;

use App\Models\Document;
use App\Models\LegacyDocument;
use App\Models\Personnel;
use App\Models\User;
use App\Support\SqlLike;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The document lists: legacy rows of one record (profile tab) and the unified "my documents" list, legacy
 * rows plus ISSUED editor documents of the account's own record (drafts never). The unified list
 * is one UNION query with the issuer name resolved by joins, so there is no per-row lookup and sorting and
 * paging happen in SQL. The dashboard card uses {@see self::recentFor()}.
 */
final class DocumentListing
{
    public const PER_PAGE = 25;

    private const SORTS = ['typ' => 'type_sort', 'nr' => 'public_id', 'ersteller' => 'issuer', 'ausgestellt' => 'issued_on'];

    /** @return LengthAwarePaginator<int, LegacyDocument> */
    public static function forPersonnel(Personnel $personnel, bool $archived, int $perPage = 6): LengthAwarePaginator
    {
        return $personnel->legacyDocuments()->with('issuerUser.personnel')
            ->where('is_archived', $archived)
            ->orderByDesc('issued_on')->orderByDesc('id')
            ->paginate($perPage, pageName: 'legacy_page');
    }

    /**
     * @param  string  $sort  typ|nr|ersteller|ausgestellt (anything else: ausgestellt)
     * @return LengthAwarePaginator<int, stdClass>
     */
    public static function forRecipient(User $user, string $search = '', string $sort = 'ausgestellt', string $dir = 'desc', int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $query = DB::query()->fromSub(self::union($user), 'd');
        if ($search !== '') {
            SqlLike::anyOf($query, ['public_id', 'issuer'], $search);
        }

        return $query->orderBy(self::SORTS[$sort] ?? 'issued_on', $dir === 'asc' ? 'asc' : 'desc')
            ->orderBy('kind')->orderByDesc('id')
            ->paginate($perPage);
    }

    /** @return list<object> the newest entries, same shape as the list rows */
    public static function recentFor(User $user, int $n = 5): array
    {
        return DB::query()->fromSub(self::union($user), 'd')
            ->orderByDesc('issued_on')->orderBy('kind')->orderByDesc('id')->limit($n)->get()->all();
    }

    private static function union(User $user): Builder
    {
        $pid = $user->personnel_id ?? 0;
        $issuer = fn (string $own) => "COALESCE({$own}, p.full_name, u.name, 'Unbekannt')";

        $legacy = DB::table('legacy_documents as l')
            ->leftJoin('users as u', 'u.id', '=', 'l.issuer_user_id')
            ->leftJoin('personnel as p', 'p.id', '=', 'u.personnel_id')
            ->where('l.personnel_id', $pid)
            ->selectRaw("'legacy' as kind, l.id, l.public_id, l.type, l.type as type_sort, NULL as title, {$issuer('l.issuer_name')} as issuer, l.issued_on");

        $editor = DB::table('documents as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.issued_by')
            ->leftJoin('personnel as p', 'p.id', '=', 'u.personnel_id')
            ->where('e.personnel_id', $pid)->where('e.status', Document::ISSUED)
            ->selectRaw("'editor' as kind, e.id, e.public_id, NULL as type, 1000 as type_sort, e.title, {$issuer('NULL')} as issuer, e.issued_at as issued_on");

        return $legacy->unionAll($editor);
    }
}
