<?php

namespace Modules\Enotf\Notifications;

use App\Models\User;
use App\Notifications\InboxNotification;
use Illuminate\Support\Facades\Notification;
use Modules\Enotf\Models\CrewMember;
use Modules\Enotf\Models\EnotfProtocol;

/** Bell kind `protokoll` (legacy NotificationManager core type): goes to the accounts of the protocol crew (users.personnel_id). */
final class ProtocolType
{
    public const KEY = 'protokoll';

    /** @return array{label: string, icon: string, module: string, ability: \Closure(User): bool} */
    public static function definition(): array
    {
        return [
            'label' => 'enotf.notification.kind', 'icon' => 'ambulance', 'module' => 'enotf',
            'ability' => fn (User $u) => $u->personnel_id !== null || $u->is_super_admin || $u->hasPermission(['admin', 'edivi.view', 'edivi.edit']),
        ];
    }

    public static function notifyVehicleCrew(int $vehicleId, string $title, string $link): void
    {
        $ids = CrewMember::query()->whereNotNull('active_key')->whereNotNull('user_id')
            ->whereHas('crew', fn ($q) => $q->where('vehicle_id', $vehicleId)->whereNull('ended_at'))->pluck('user_id')->unique();
        $users = User::query()->where('is_active', true)->whereIn('id', $ids)->get();
        Notification::send($users, new InboxNotification(self::KEY, $title, null, $link));
    }

    public static function notifyCrew(EnotfProtocol $protocol, string $title, ?int $except = null): void
    {
        $personnel = $protocol->crew()->whereNotNull('personnel_id')->pluck('personnel_id')->unique();
        if ($personnel->isEmpty()) {
            return;
        }
        $users = User::query()->where('is_active', true)->whereIn('personnel_id', $personnel)
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))->get();
        Notification::send($users, new InboxNotification(self::KEY, $title, $protocol->vehicle_transport_label ?? $protocol->vehicle_na_label, '/enotf/p/'.rawurlencode($protocol->enr)));
    }
}
