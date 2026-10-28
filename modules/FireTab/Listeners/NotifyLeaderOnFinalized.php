<?php

namespace Modules\FireTab\Listeners;

use Modules\FireTab\Events\FireIncidentFinalized;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Notifications\FireProtocolType;

final class NotifyLeaderOnFinalized
{
    public function handle(FireIncidentFinalized $event): void
    {
        $incident = FireIncident::query()->find($event->incidentId);
        if ($incident !== null) {
            FireProtocolType::notifyLeader($incident, __('firetab.notification.finalized', ['number' => $incident->number ?? '#'.$incident->id]), $incident->location);
        }
    }
}
