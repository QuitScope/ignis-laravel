<?php

namespace Modules\Enotf\Support;

use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Models\EnotfProtocol;

/** Status transitions of a protocol. The caller checks the policy; this re-checks the state under the row lock. */
class ProtocolLifecycle
{
    public function withdraw(EnotfProtocol $protocol, User $by): bool
    {
        $done = DB::transaction(function () use ($protocol, $by) {
            $locked = EnotfProtocol::query()->lockForUpdate()->find($protocol->id);
            if ($locked === null || ! $locked->isOpen()) {
                return false;
            }
            $locked->forceFill(['status' => ProtocolStatus::Withdrawn, 'withdrawn_at' => now(), 'withdrawn_by' => $by->id])->save();

            return true;
        });
        if ($done) {
            AuditLogger::log($by, 'Protokoll zurückgezogen', $protocol->enr, 'eNOTF', context: ['protocol_id' => $protocol->id]);
        }

        return $done;
    }
}
