<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Models\User;
use App\Models\Vehicle;
use App\Modules\ModuleRegistry;
use Closure;

/**
 * "Hinweise" (legacy hints.php + OpenTasks): vehicles in status 6 (one hint for all of them) and vehicles with an open
 * blocking defect, plus the hints of module sources (addSource; eNOTF: unreleased protocols, Anträge: open
 * submissions, ...); a calm hint when nothing is open. Without a right on any source the widget is gone. A source of
 * a switched-off module is skipped.
 *
 * A hint is {tone: ok|info|warn|danger, icon: Font Awesome name, title, text?, href?, action?: {label, href},
 * progress?: {value: 0..100, label}, since?: ISO instant}. Title or text may hold `:since`; the client puts the time
 * elapsed since `since` there (relative, in the viewer's language).
 *
 * @phpstan-type Hint array{tone: string, icon: string, title: string, text?: string, href?: string, action?: array{label: string, href: string}, progress?: array{value: int, label: string}, since?: string}
 */
final class HintsWidget implements Widget
{
    private const NAMES = 3;

    /** @var list<array{module: string, allowed: Closure(User): bool, hints: Closure(User): list<Hint>, top: bool}> */
    private array $sources = [];

    /**
     * @param  Closure(User): bool  $allowed  rights only, no queries
     * @param  Closure(User): list<Hint>  $hints
     * @param  bool  $top  before the vehicle hints (legacy order: unreleased protocols come first)
     */
    public function addSource(string $module, Closure $allowed, Closure $hints, bool $top = false): void
    {
        $this->sources[] = ['module' => $module, 'allowed' => $allowed, 'hints' => $hints, 'top' => $top];
    }

    public function key(): string
    {
        return 'hints';
    }

    public function area(): string
    {
        return 'side';
    }

    public function order(): int
    {
        return 10;
    }

    public function allowed(User $user): bool
    {
        return $user->can('vehicle.view') || $this->sourcesFor($user) !== [];
    }

    /** @return array{hints: list<Hint>} */
    public function data(User $user): array
    {
        $sources = $this->sourcesFor($user);
        $hints = [];
        foreach ($sources as $source) {
            $source['top'] && array_push($hints, ...($source['hints'])($user));
        }
        if ($user->can('vehicle.view')) {
            array_push($hints, ...self::vehicleHints());
        }
        foreach ($sources as $source) {
            $source['top'] || array_push($hints, ...($source['hints'])($user));
        }

        return ['hints' => $hints ?: [['tone' => 'ok', 'icon' => 'circle-check', 'title' => __('dashboard.hints.calm'), 'text' => __('dashboard.hints.calm_text')]]];
    }

    /** @return list<Hint> */
    private static function vehicleHints(): array
    {
        $hints = [];
        $down = Vehicle::active()->where('fms_status', '6')->orderBy('name')->get(['id', 'name', 'fms_status_at']);
        if ($down->count() === 1) {
            $v = $down[0];
            $hints[] = ['tone' => 'warn', 'icon' => 'clock', 'href' => "/vehicles/{$v->id}", 'text' => __('dashboard.hints.down_text')]
                + ($v->fms_status_at === null
                    ? ['title' => __('dashboard.hints.down_one', ['name' => $v->name])]
                    : ['title' => __('dashboard.hints.down_one_since', ['name' => $v->name]), 'since' => $v->fms_status_at->toIso8601String()]);
        } elseif ($down->isNotEmpty()) {
            $hints[] = ['tone' => 'warn', 'icon' => 'clock', 'href' => '/vehicles', 'title' => __('dashboard.hints.down_many', ['count' => $down->count()]),
                'text' => __('dashboard.hints.down_names', ['names' => $down->pluck('name')->implode(', ')])];
        }
        $blocked = Vehicle::active()->whereHas('blockingDefects')->orderBy('name')->get(['id', 'name']);
        if ($blocked->isNotEmpty()) {
            $hints[] = ['tone' => 'danger', 'icon' => 'triangle-exclamation', 'title' => self::text('dashboard.hints.blocked', $blocked->pluck('name')->all()), 'href' => '/vehicles/defects'];
        }

        return $hints;
    }

    /**
     * "N things: a, b, c +2" with the plural form of $key (`:count`, `:names`); $count when $names is only the first few.
     *
     * @param  list<string>  $names
     */
    public static function text(string $key, array $names, ?int $count = null): string
    {
        $count ??= count($names);
        $shown = implode(', ', array_slice($names, 0, self::NAMES));
        $more = $count - min(count($names), self::NAMES);

        return trans_choice($key, $count, ['count' => $count, 'names' => $shown.($more > 0 ? ' +'.$more : '')]);
    }

    /** @return list<array{module: string, allowed: Closure(User): bool, hints: Closure(User): list<Hint>, top: bool}> */
    private function sourcesFor(User $user): array
    {
        return array_values(array_filter($this->sources, fn (array $s) => ModuleRegistry::active($s['module']) && ($s['allowed'])($user)));
    }
}
