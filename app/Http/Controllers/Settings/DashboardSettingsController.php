<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardCategoryRequest;
use App\Http\Requests\DashboardLinkRequest;
use App\Http\Requests\ReorderDashboardRequest;
use App\Models\DashboardCategory;
use App\Models\DashboardLink;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Quick-link settings (legacy Settings/DashboardController). All entries are global audit entries of the module
 * `Dashboard`, with the legacy texts: created without an id, updated/deleted with `[ID: n]`.
 */
class DashboardSettingsController extends Controller
{
    private const MODULE = 'Dashboard';

    public function index(): Response
    {
        $this->authorize('system.manageDashboard');

        return Inertia::render('Settings/Dashboard', [
            'categories' => DashboardCategory::query()->ordered()->with('links')->get()->map(fn (DashboardCategory $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'sort_order' => $c->sort_order,
                'links' => $c->links->map(fn (DashboardLink $l) => [
                    'id' => $l->id, 'title' => $l->title, 'url' => $l->url, 'icon' => $l->icon, 'sort_order' => $l->sort_order,
                ])->all(),
            ])->all(),
        ]);
    }

    public function storeCategory(DashboardCategoryRequest $request): RedirectResponse
    {
        $data = $request->categoryData();
        $data['sort_order'] ??= $this->nextPosition(DashboardCategory::query());
        $category = DashboardCategory::create($data);
        $this->audit($request, 'Kategorie erstellt', null, 'Titel: '.$category->title, $category->id);

        return back();
    }

    public function updateCategory(DashboardCategoryRequest $request, DashboardCategory $category): RedirectResponse
    {
        $category->update($request->categoryData());
        $this->audit($request, 'Kategorie aktualisiert', $category->id, 'Titel: '.$category->title, $category->id);

        return back();
    }

    public function destroyCategory(Request $request, DashboardCategory $category): RedirectResponse
    {
        $this->authorize('system.manageDashboard');

        $category->delete();
        $this->audit($request, 'Kategorie gelöscht', $category->id, 'Titel: '.$category->title, $category->id);

        return back();
    }

    public function storeLink(DashboardLinkRequest $request): RedirectResponse
    {
        $data = $request->linkData();
        $data['sort_order'] ??= $this->nextPosition(DashboardLink::where('dashboard_category_id', $data['dashboard_category_id']));
        $link = DashboardLink::create($data);
        $this->audit($request, 'Verlinkung erstellt', null, 'Titel: '.$link->title, $link->id);

        return back();
    }

    public function updateLink(DashboardLinkRequest $request, DashboardLink $link): RedirectResponse
    {
        $link->update($request->linkData());
        $this->audit($request, 'Verlinkung aktualisiert', $link->id, 'Titel: '.$link->title, $link->id);

        return back();
    }

    public function destroyLink(Request $request, DashboardLink $link): RedirectResponse
    {
        $this->authorize('system.manageDashboard');

        $link->delete();
        $this->audit($request, 'Verlinkung gelöscht', $link->id, 'Titel: '.$link->title, $link->id);

        return back();
    }

    public function reorderCategories(ReorderDashboardRequest $request): RedirectResponse
    {
        $ids = $request->ids();

        // ponytail: no lockForUpdate; two clients reordering at once can leave tied sort_orders (the id breaks the tie).
        DB::transaction(function () use ($ids) {
            $known = array_flip(DashboardCategory::whereIn('id', $ids)->pluck('id')->all());
            $position = 0;
            foreach ($ids as $id) {
                if (isset($known[$id])) {
                    DashboardCategory::whereKey($id)->update(['sort_order' => $position++]);
                }
            }
        });
        AuditLogger::log($request->user(), 'Kategorie Reihenfolge geändert', null, self::MODULE, true);

        return back();
    }

    public function reorderLinks(ReorderDashboardRequest $request): RedirectResponse
    {
        $category = $request->integer('category_id');
        $ids = $request->ids();

        DB::transaction(function () use ($category, $ids) {
            $known = array_flip(DashboardLink::whereIn('id', $ids)->pluck('id')->all());
            $position = 0;
            foreach ($ids as $id) {
                if (isset($known[$id])) {
                    DashboardLink::whereKey($id)->update(['dashboard_category_id' => $category, 'sort_order' => $position++]);
                }
            }
        });
        AuditLogger::log($request->user(), 'Verlinkung Reihenfolge geändert', null, self::MODULE, true);

        return back();
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<*>  $scope */
    private function nextPosition($scope): int
    {
        return min(9999, ((int) $scope->max('sort_order')) + 1);
    }

    private function audit(Request $request, string $action, ?int $idInText, string $details, int $id): void
    {
        AuditLogger::log($request->user(), $action.($idInText === null ? '' : ' [ID: '.$idInText.']'), $details, self::MODULE, true, ['id' => $id]);
    }
}
