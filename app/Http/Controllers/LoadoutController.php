<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoadoutCategoryRequest;
use App\Http\Requests\LoadoutItemRequest;
use App\Http\Requests\ReorderLoadoutRequest;
use App\Models\LoadoutCategory;
use App\Models\LoadoutItem;
use App\Models\Vehicle;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Loadout lists (legacy beladelisten page and beladung_handler). The lists are global: a category belongs to a vehicle
 * type by text (null = all), so audit entries carry no vehicle id.
 */
class LoadoutController extends Controller
{
    private const MODULE = 'Fahrzeuge';

    public function index(Request $request): Response
    {
        $this->authorize('vehicle.view');

        return Inertia::render('Settings/Vehicles/Loadouts', [
            'categories' => LoadoutCategory::query()->ordered()->with('items')->get()->map(fn (LoadoutCategory $c) => self::row($c, true))->all(),
            'types' => Vehicle::query()->select('type')->union(LoadoutCategory::query()->whereNotNull('vehicle_type')->select('vehicle_type'))
                ->orderBy('type')->pluck('type')->all(),
            'can' => ['manage' => $request->user()->can('vehicle.manage')],
        ]);
    }

    /**
     * The fields the settings page and the vehicle tab share.
     *
     * @return array<string, mixed>
     */
    public static function row(LoadoutCategory $c, bool $withMeta = false): array
    {
        return [
            'id' => $c->id,
            'title' => $c->title,
            'compartment' => $c->compartment,
            ...($withMeta ? ['vehicle_type' => $c->vehicle_type, 'sort_order' => $c->sort_order] : []),
            'items' => $c->items->map(fn (LoadoutItem $i) => ['id' => $i->id, 'title' => $i->title, 'quantity' => $i->quantity])->all(),
        ];
    }

    public function storeCategory(LoadoutCategoryRequest $request): RedirectResponse
    {
        $category = LoadoutCategory::create($request->categoryData());
        $this->audit($request, 'Kategorie erstellt', $category->id, 'Titel: '.$category->title);

        return back();
    }

    public function updateCategory(LoadoutCategoryRequest $request, LoadoutCategory $category): RedirectResponse
    {
        $category->update($request->categoryData());
        $this->audit($request, 'Kategorie aktualisiert', $category->id, 'Titel: '.$category->title);

        return back();
    }

    public function destroyCategory(Request $request, LoadoutCategory $category): RedirectResponse
    {
        $this->authorize('vehicle.manage');

        $category->delete();
        $this->audit($request, 'Kategorie gelöscht', $category->id, 'Titel: '.$category->title);

        return back();
    }

    public function storeItem(LoadoutItemRequest $request, LoadoutCategory $category): RedirectResponse
    {
        $item = $category->items()->create([
            'title' => $request->validated('title'),
            'quantity' => (int) $request->validated('quantity'),
            'sort_order' => $this->nextPosition($category->id),
        ]);
        $this->audit($request, 'Gegenstand erstellt', $item->id, 'Titel: '.$item->title);

        return back();
    }

    public function updateItem(LoadoutItemRequest $request, LoadoutItem $item): RedirectResponse
    {
        $data = ['quantity' => (int) $request->validated('quantity')];
        if (! $request->isMethod('PATCH')) {
            $data['title'] = $request->validated('title');
            $target = $request->validated('category_id');
            if ($target !== null && (int) $target !== $item->loadout_category_id) {
                $data['loadout_category_id'] = (int) $target;
                $data['sort_order'] = $this->nextPosition((int) $target);
            }
        }
        $item->update($data);
        $this->audit($request, 'Gegenstand aktualisiert', $item->id, 'Titel: '.$item->title.' | Menge: '.$item->quantity);

        return back();
    }

    public function destroyItem(Request $request, LoadoutItem $item): RedirectResponse
    {
        $this->authorize('vehicle.manage');

        $item->delete();
        $this->audit($request, 'Gegenstand gelöscht', $item->id, 'Titel: '.$item->title);

        return back();
    }

    public function reorder(ReorderLoadoutRequest $request): RedirectResponse
    {
        $category = $request->integer('category_id');
        $ids = $request->ids();

        // ponytail: no lockForUpdate; two clients reordering at once can leave tied sort_orders (the id breaks the tie). Lock the rows if that matters.
        DB::transaction(function () use ($category, $ids) {
            $known = array_flip(LoadoutItem::whereIn('id', $ids)->pluck('id')->all());
            $position = 0;
            foreach ($ids as $id) {
                if (isset($known[$id])) {
                    LoadoutItem::whereKey($id)->update(['loadout_category_id' => $category, 'sort_order' => $position++]);
                }
            }
        });
        AuditLogger::log($request->user(), 'Beladeliste Reihenfolge geändert', null, self::MODULE, true);

        return back();
    }

    private function nextPosition(int $categoryId): int
    {
        return ((int) LoadoutItem::where('loadout_category_id', $categoryId)->max('sort_order')) + 1;
    }

    private function audit(Request $request, string $verb, int $id, string $details): void
    {
        AuditLogger::log($request->user(), 'Beladeliste '.$verb.' [ID: '.$id.']', $details, self::MODULE, true);
    }
}
