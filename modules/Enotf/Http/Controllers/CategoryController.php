<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Enotf\Models\EnotfCategory;

/** /settings/enotf/categories (right enotf.settings): quicklink categories of the overview page. */
class CategoryController extends Controller
{
    public function index(): Response
    {
        $this->authorize('enotf.settings');

        return Inertia::render('Enotf/Settings/Categories', [
            'categories' => EnotfCategory::query()->withCount('quicklinks')->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (EnotfCategory $c) => ['id' => $c->id, 'name' => $c->name, 'sort_order' => $c->sort_order, 'active' => $c->active, 'links' => $c->quicklinks_count])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('enotf.settings');
        $data = $this->data($request);
        $category = (new EnotfCategory)->forceFill($data + ['slug' => $this->slug($data['name'])]);
        $category->save();
        AuditLogger::log($request->user(), 'eNOTF-Kategorie erstellt', $category->name, 'eNOTF', false, ['category_id' => $category->id]);

        return back();
    }

    public function update(Request $request, EnotfCategory $category): RedirectResponse
    {
        $this->authorize('enotf.settings');
        $category->forceFill($this->data($request))->save();
        AuditLogger::log($request->user(), 'eNOTF-Kategorie bearbeitet', $category->name, 'eNOTF', false, ['category_id' => $category->id]);

        return back();
    }

    public function destroy(Request $request, EnotfCategory $category): RedirectResponse
    {
        $this->authorize('enotf.settings');
        if ($category->quicklinks()->exists()) {
            throw ValidationException::withMessages(['category' => __('enotf.settings.category_in_use')]);
        }
        $category->delete();
        AuditLogger::log($request->user(), 'eNOTF-Kategorie gelöscht', $category->name, 'eNOTF', false, ['category_id' => $category->id]);

        return back();
    }

    /** @return array{name: string, sort_order: int, active: bool} */
    private function data(Request $request): array
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:100'], 'sort_order' => ['required', 'integer', 'between:-9999,9999'], 'active' => ['required', 'boolean']]);

        return ['name' => trim($d['name']), 'sort_order' => (int) $d['sort_order'], 'active' => (bool) $d['active']];
    }

    private function slug(string $name): string
    {
        $base = Str::slug($name) ?: 'kategorie';
        $slug = $base;
        for ($i = 2; EnotfCategory::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "$base-$i";
        }

        return $slug;
    }
}
