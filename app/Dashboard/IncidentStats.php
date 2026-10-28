<?php

namespace App\Dashboard;

use App\Models\User;
use App\Modules\ModuleRegistry;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Einsätze heute" and the incidents sparklines (legacy Overview::incidents). Modules add their source (eNOTF `rd`,
 * FireTab `fire`) with the right that lets the viewer see their list; a source of a switched-off module or without
 * the right is left out, without any source there are no figures (null). Hours run from 0:00 to the running hour,
 * days over the last seven, `yesterday` counts yesterday up to the same time, all in the organisation time zone.
 */
final class IncidentStats
{
    /** @var array<string, array{module: string, allowed: Closure(User): bool, query: Closure(): Builder<*>, column: string}> */
    private array $sources = [];

    /**
     * @param  Closure(User): bool  $allowed  rights only, no queries
     * @param  Closure(): Builder<*>  $query  the incidents of the source (visible ones only)
     */
    public function addSource(string $key, string $module, Closure $allowed, Closure $query, string $column): void
    {
        $this->sources[$key] = ['module' => $module, 'allowed' => $allowed, 'query' => $query, 'column' => $column];
    }

    public function allows(User $user): bool
    {
        return $this->allowedSources($user) !== [];
    }

    /**
     * The figures for now, computed once per request (both incident widgets read them). Memoised on the request, not
     * with once(): that would outlive the request in a test making several requests (and in a long-running worker).
     *
     * @return ?array{today: int, rd: ?int, fire: ?int, yesterday: int, hours: list<int>, days: list<int>}
     */
    public function current(User $user): ?array
    {
        $memo = request()->attributes;
        $key = 'dashboard.incidents.'.$user->id;
        if (! $memo->has($key)) {
            $memo->set($key, $this->for($user, CarbonImmutable::now()));
        }

        return $memo->get($key);
    }

    /** @return ?array{today: int, rd: ?int, fire: ?int, yesterday: int, hours: list<int>, days: list<int>} */
    public function for(User $user, CarbonImmutable $now): ?array
    {
        $sources = $this->allowedSources($user);
        if ($sources === []) {
            return null;
        }

        $zone = (string) config('ignis.timezone');
        $local = $now->setTimezone($zone);
        $today = $local->startOfDay();
        $first = $today->subDays(6);
        $day = array_flip(array_map(fn (int $i) => $first->addDays($i)->toDateString(), range(0, 6)));
        $yesterdayFrom = $today->subDay();
        $yesterdayTo = $local->subDay();

        $hours = array_fill(0, $local->hour + 1, 0);
        $days = array_fill(0, 7, 0);
        $counts = [];
        $yesterday = 0;
        foreach ($sources as $key => $source) {
            $counts[$key] = 0;
            // Plain timestamps, bucketed here: the same on SQLite and MariaDB, no HOUR()/DATE() dialects or zone tables.
            // ponytail: one row per incident of the week; group in SQL if a week ever holds > 10 000 incidents.
            $stamps = ($source['query'])()->where($source['column'], '>=', $first->utc())->where($source['column'], '<=', $now->utc())
                ->pluck($source['column']);
            foreach ($stamps as $stamp) {
                $at = CarbonImmutable::make($stamp)?->setTimezone($zone);
                if ($at === null) {
                    continue;
                }
                $date = $at->toDateString();
                if (isset($day[$date])) {
                    $days[$day[$date]]++;
                }
                if ($date === $today->toDateString()) {
                    $hours[$at->hour]++;
                    $counts[$key]++;
                }
                if ($at >= $yesterdayFrom && $at <= $yesterdayTo) {
                    $yesterday++;
                }
            }
        }

        return [
            'today' => array_sum($counts),
            'rd' => $counts['rd'] ?? null,
            'fire' => $counts['fire'] ?? null,
            'yesterday' => $yesterday,
            'hours' => $hours,
            'days' => $days,
        ];
    }

    /** @return array<string, array{module: string, allowed: Closure(User): bool, query: Closure(): Builder<*>, column: string}> */
    private function allowedSources(User $user): array
    {
        return array_filter($this->sources, fn (array $s) => ModuleRegistry::active($s['module']) && ($s['allowed'])($user));
    }
}
