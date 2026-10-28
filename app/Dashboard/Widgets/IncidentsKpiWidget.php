<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\IncidentStats;
use App\Dashboard\Widget;
use App\Models\User;

/** KPI "Einsätze heute" (legacy kpis.php): today's count, the share per source and the delta to yesterday at this time. */
final class IncidentsKpiWidget implements Widget
{
    public function __construct(private readonly IncidentStats $stats) {}

    public function key(): string
    {
        return 'incidents_kpi';
    }

    public function area(): string
    {
        return 'kpi';
    }

    public function order(): int
    {
        return 10;
    }

    public function allowed(User $user): bool
    {
        return $this->stats->allows($user);
    }

    public function data(User $user): ?array
    {
        $s = $this->stats->current($user);

        return $s === null ? null : ['today' => $s['today'], 'rd' => $s['rd'], 'fire' => $s['fire'], 'delta' => $s['today'] - $s['yesterday']];
    }
}
