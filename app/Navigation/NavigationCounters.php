<?php

namespace App\Navigation;

use App\Models\User;
use App\Modules\ModuleRegistry;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Counters next to sidebar entries (legacy counters.php), e.g. unread inbox mails of the module Mail.
 * Modules register theirs; for() asks only the counters of switched-on modules, for a signed-in account. A resolver
 * answers null when the account does not see the entry (no right, no mailbox); one that throws is logged and left out,
 * a counter never breaks a page. Shared lazily as the Inertia prop `nav.counters` and in the bell's poll.
 */
final class NavigationCounters
{
    /** @var array<string, array{resolver: Closure(User): ?int, module: string}> */
    private array $counters = [];

    /** @param  Closure(User): ?int  $resolver */
    public function register(string $key, Closure $resolver, string $module): void
    {
        $this->counters[$key] = ['resolver' => $resolver, 'module' => $module];
    }

    /** @return array<string, int> key => count, nothing for guests */
    public function for(?User $user): array
    {
        if ($user === null) {
            return [];
        }
        $counts = [];
        foreach ($this->counters as $key => $counter) {
            if (! ModuleRegistry::active($counter['module'])) {
                continue;
            }
            try {
                $count = ($counter['resolver'])($user);
            } catch (Throwable $e) {
                Log::error("Navigation counter {$key} failed", ['exception' => $e::class]);

                continue;
            }
            if ($count !== null) {
                $counts[$key] = $count;
            }
        }

        return $counts;
    }
}
