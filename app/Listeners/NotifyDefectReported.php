<?php

namespace App\Listeners;

use App\Events\VehicleDefectReported;
use App\Models\User;
use App\Notifications\InboxNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;

/**
 * A new defect goes to every active account that may see `vehicle_defect` (admin, vehicles.view or vehicles.manage,
 * manage alone too), never to the reporter (legacy DefectReporter::notifyStaff). The event fires after the
 * commit, so a rolled back report sends nothing. A failure is reported, never fails the report.
 * The text is stored in the locale of the request that sent it (the install-wide locale today).
 */
class NotifyDefectReported
{
    private const PERMISSIONS = ['admin', 'vehicles.view', 'vehicles.manage'];

    public function handle(VehicleDefectReported $event): void
    {
        $defect = $event->defect;
        rescue(function () use ($defect) {
            $granted = fn (Builder $q) => $q->whereIn('name', self::PERMISSIONS);
            $recipients = User::active()
                ->where(fn (Builder $q) => $q->where('is_super_admin', true)
                    ->orWhereHas('roles.permissions', $granted)
                    ->orWhereHas('permissions', $granted))
                ->when($defect->reported_by !== null, fn ($q) => $q->whereKeyNot($defect->reported_by))
                ->get();
            $vehicle = $defect->vehicle->name ?? '';
            Notification::send($recipients, new InboxNotification(
                'vehicle_defect',
                trans('notifications.defect.title', ['title' => $defect->title]),
                trans($defect->is_operable ? 'notifications.defect.message' : 'notifications.defect.message_blocking', ['vehicle' => $vehicle]),
                "/vehicles/defects?vehicle={$defect->vehicle_id}",
            ));
        });
    }
}
