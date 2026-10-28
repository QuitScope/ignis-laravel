<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\IncidentStats;
use App\Dashboard\Widget;
use App\Models\User;

/** "Einsätze" (legacy incidents.php): per hour since 0:00 and per day over seven days, with the delta to yesterday at this time. */
final class IncidentsWidget implements Widget
{
    public function __construct(private readonly IncidentStats $stats) {}

    public function key(): string
    {
        return 'incidents';
    }

    public function area(): string
    {
        return 'main';
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

        return $s === null ? null : ['hours' => $s['hours'], 'days' => $s['days'], 'delta' => $s['today'] - $s['yesterday']];
    }
}
