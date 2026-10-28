<?php

namespace Modules\FireTab\Listeners;

use App\Discord\DiscordWebhook;
use App\Discord\Embeds;
use App\Discord\WebhookKind;
use Modules\FireTab\Events\FireIncidentFinalized;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Support\OrganizationTime;

/** "zur QM-Sichtung abgeschlossen" (text from the shared embed). No personal data of caller or owner. */
final class DispatchDiscordOnFinalized
{
    public function handle(FireIncidentFinalized $event): void
    {
        $i = FireIncident::query()->with('leader')->find($event->incidentId);
        if ($i === null) {
            return;
        }
        DiscordWebhook::dispatch(WebhookKind::FireProtocol, Embeds::fireProtocol([
            'id' => $i->id, 'incident_number' => $i->number, 'location' => $i->location, 'keyword' => $i->keyword,
            'started_at' => OrganizationTime::display($i->started_at), 'leader_name' => $i->leaderName(),
        ]));
    }
}
