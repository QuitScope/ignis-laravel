<?php

namespace Modules\Enotf\Support;

use App\Models\User;
use App\Vehicles\CrewSession;

/**
 * Vehicle and personnel record of the current eNOTF crew session (Fahrzeugsitzung); bind another implementation to override.
 * Only a still VALID session counts (record still linked, vehicle still eligible, membership active): show, PATCH, release and print sit
 * outside crew:enotf and must not trust a stale session.
 */
class CrewContext
{
    public function vehicleId(): ?int
    {
        return $this->crew()['vehicle_id'] ?? null;
    }

    public function personnelId(): ?int
    {
        return $this->crew()['personnel_id'] ?? null;
    }

    /** @return array<string, mixed>|null */
    private function crew(): ?array
    {
        $session = CrewSession::for('enotf');
        $user = auth()->user();

        return $user instanceof User && $session->valid($user) && app(CrewService::class)->member($user) !== null ? $session->current() : null;
    }
}
