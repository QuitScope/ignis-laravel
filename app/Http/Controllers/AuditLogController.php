<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\SqlLike;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class AuditLogController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $this->authorize('viewAuditLog', User::class);

        $search = trim((string) $request->query('search', ''));
        $module = trim((string) $request->query('module', ''));
        $like = SqlLike::contains($search);

        $entries = Activity::query()->with('causer')
            ->where('is_global', true)
            ->when($module !== '', fn ($q) => $q->where('log_name', $module))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw('description '.SqlLike::CLAUSE, [$like])
                ->orWhereRaw('details '.SqlLike::CLAUSE, [$like])
                ->orWhereHasMorph('causer', [User::class], fn ($u) => $u->whereRaw('username '.SqlLike::CLAUSE, [$like]))))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Activity $a) => [
                'id' => $a->id,
                'time' => $a->created_at?->format('Y-m-d H:i:s'),
                'module' => $a->log_name,
                'action' => $a->description,
                'details' => $a->getAttribute('details'),
                'user' => $a->causer instanceof User ? $a->causer->username : null,
            ]);

        return Inertia::render('Audit/Index', [
            'entries' => $entries,
            'modules' => Activity::query()->where('is_global', true)->whereNotNull('log_name')
                ->distinct()->orderBy('log_name')->pluck('log_name'),
            'filters' => ['search' => $search, 'module' => $module],
        ]);
    }
}
