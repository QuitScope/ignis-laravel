<?php

namespace App\Http\Controllers;

use App\Dashboard\DashboardWidgets;
use App\Modules\ModuleRegistry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The start page. `layout` lists the keys of the allowed widgets per area (rights only, no data queries); `widgets`
 * holds one lazy closure per allowed key, so a widget without the right is never evaluated. The supported refresh is
 * `only: ['widgets']` (all allowed widgets); a single-widget reload (`only: ['widgets.vehicles']`) is NOT supported:
 * Inertia shallow-merges partial props and would wipe the other widgets (deepMerge is deliberately not used). `header.date` is an instant: the browser formats it in its own zone
 * and language (the app zone is UTC).
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, DashboardWidgets $registry): Response
    {
        $user = $request->user();
        $layout = [];
        $widgets = [];
        foreach ($registry->allowedFor($user) as $area => $list) {
            $layout[$area] = array_map(fn ($w) => $w->key(), $list);
            foreach ($list as $widget) {
                $widgets[$widget->key()] = fn () => $registry->data($widget, $user);
            }
        }

        return Inertia::render('Home', [
            'header' => ['date' => now()->toIso8601String(), 'actions' => ModuleRegistry::active('forms') ? [['label' => 'home.apply', 'href' => '/forms']] : []],
            'layout' => $layout,
            'widgets' => $widgets,
        ]);
    }
}
