<?php

namespace Modules\Enotf\Listeners;

use Modules\Enotf\Events\ProtocolReleased;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Notifications\ProtocolType;

final class NotifyCrewOnReleased
{
    public function handle(ProtocolReleased $event): void
    {
        $protocol = EnotfProtocol::query()->find($event->protocolId);
        if ($protocol !== null) {
            ProtocolType::notifyCrew($protocol, __('enotf.notification.released', ['enr' => $protocol->enr]), $event->userId);
        }
    }
}
