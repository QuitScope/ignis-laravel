<?php

namespace Modules\FireTab\Notifications;

use App\Models\User;
use App\Notifications\InboxNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Modules\FireTab\Models\FireIncident;

/** Bell kind `fire_protocol`: goes to the account of the incident leader (users.personnel_id). Visible to leader accounts and QM. */
final class FireProtocolType
{
    public const KEY = 'fire_protocol';

    /** @return array{label: string, icon: string, module: string, ability: \Closure(User): bool} */
    public static function definition(): array
    {
        return [
            'label' => 'firetab.notification.kind', 'icon' => 'flame', 'module' => 'firetab',
            'ability' => fn (User $u) => $u->personnel_id !== null || $u->is_super_admin || $u->hasPermission(['admin', 'fire.incident.qm']),
        ];
    }

    public static function notifyLeader(FireIncident $incident, string $title, ?string $text, ?int $except = null): void
    {
        if ($incident->leader_personnel_id === null) {
            return;
        }
        $users = User::query()->where('is_active', true)->where('personnel_id', $incident->leader_personnel_id)
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))->get();
        foreach ($users as $user) {
            Notification::send($user, new InboxNotification(self::KEY, $title, $text, self::link($incident, $user)));
        }
    }

    public static function link(FireIncident $incident, User $user): string
    {
        return Gate::forUser($user)->allows('viewQm', FireIncident::class) ? "/firetab/qm/incidents/{$incident->id}" : "/firetab/incidents/{$incident->id}";
    }
}
