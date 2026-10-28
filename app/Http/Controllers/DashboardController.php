<?php

namespace App\Http\Controllers;

use App\Models\DashboardCategory;
use App\Models\DashboardLink;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The quick links page (legacy dashboard.php): every signed-in user, categories with their links in order. */
class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Dashboard', [
            'categories' => DashboardCategory::query()->ordered()->with('links')->get()->map(fn (DashboardCategory $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'links' => $c->links->map(fn (DashboardLink $l) => ['id' => $l->id, 'title' => $l->title, 'url' => $l->url, 'icon' => $l->icon])->all(),
            ])->all(),
            'can' => ['configure' => $request->user()->can('system.manageDashboard')],
        ]);
    }
}
