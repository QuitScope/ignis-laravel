<?php

namespace App\Dashboard;

use App\Models\User;
use App\Modules\BelongsToModule;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The widgets of the start page. Core ones are registered in AppServiceProvider, modules append theirs with
 * register() (Einsätze, eNOTF, Anträge, … come with their phases). A widget whose allowed() or data() throws drops
 * out alone and is logged; the page never answers 500 because of one widget.
 */
final class DashboardWidgets
{
    public const AREAS = ['top', 'kpi', 'main', 'side'];

    /** @var list<Widget> */
    private array $widgets = [];

    public function register(Widget $widget): void
    {
        foreach ($this->widgets as $known) {
            if ($known->key() === $widget->key()) {
                Log::warning('Dashboard: duplicate widget key '.$widget->key().' ignored', ['class' => $widget::class]);

                return;
            }
        }
        $this->widgets[] = $widget;
    }

    /**
     * The widgets the viewer is allowed, per area in order (registration order breaks ties), without those of
     * switched-off modules. Only rights are checked here; data() is left to {@see self::data()} so it can run lazily.
     *
     * @return array<string, list<Widget>>
     */
    public function allowedFor(User $user): array
    {
        $areas = array_fill_keys(self::AREAS, []);
        foreach ($this->widgets as $widget) {
            if (! ModuleRegistry::active($widget instanceof BelongsToModule ? $widget->module() : null)) {
                continue;
            }
            try {
                if ($widget->allowed($user) && in_array($widget->area(), self::AREAS, true)) {
                    $areas[$widget->area()][] = $widget;
                }
            } catch (Throwable $e) {
                Log::error('Dashboard: widget '.$widget::class.' failed', ['exception' => $e::class]);
            }
        }
        foreach ($areas as &$list) {
            usort($list, fn (Widget $a, Widget $b) => $a->order() <=> $b->order());
        }

        return $areas;
    }

    /** @return ?array<string, mixed> null when empty or when the widget failed */
    public function data(Widget $widget, User $user): ?array
    {
        try {
            return $widget->data($user);
        } catch (Throwable $e) {
            Log::error('Dashboard: widget '.$widget::class.' failed', ['exception' => $e::class]);

            return null;
        }
    }
}
