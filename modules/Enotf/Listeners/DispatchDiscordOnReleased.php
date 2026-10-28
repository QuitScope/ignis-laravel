<?php

namespace Modules\Enotf\Listeners;

use App\Discord\DiscordWebhook;
use App\Discord\Embeds;
use App\Discord\WebhookKind;
use Modules\Enotf\Events\ProtocolReleased;
use Modules\Enotf\Models\EnotfProtocol;

/** Legacy DispatchDiscordWebhookOnEnotfReleased: ENR and release time only, never patient data. */
final class DispatchDiscordOnReleased
{
    public function handle(ProtocolReleased $event): void
    {
        $p = EnotfProtocol::query()->find($event->protocolId);
        if ($p === null) {
            return;
        }
        DiscordWebhook::dispatch(WebhookKind::EnotfProtocol, Embeds::enotfProtocol([
            'enr' => $p->enr,
            'last_edit' => $p->released_at?->setTimezone((string) config('ignis.timezone'))->format('d.m.Y H:i'),
        ]));
    }
}
