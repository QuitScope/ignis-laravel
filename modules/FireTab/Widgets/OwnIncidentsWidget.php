<?php

namespace Modules\FireTab\Widgets;

use App\Dashboard\Widget;
use App\Models\User;
use App\Modules\BelongsToModule;
use Illuminate\Database\Eloquent\Builder;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Notifications\FireProtocolType;
use Modules\FireTab\Support\OrganizationTime;

/**
 * "Eigene fireTab-Protokolle" (legacy OwnRecords::firetabProtocols): the newest incidents the account's personnel record
 * leads, continued by /me/protocols. Shown while the module is on, also without a record (empty state).
 */
final class OwnIncidentsWidget implements BelongsToModule, Widget
{
    public const ROWS = 5;

    public const ALL = '/me/protocols?source=firetab';

    public function module(): string
    {
        return 'firetab';
    }

    public function key(): string
    {
        return 'own_incidents';
    }

    public function area(): string
    {
        return 'main';
    }

    public function order(): int
    {
        return 37;
    }

    public function allowed(User $user): bool
    {
        return true;
    }

    /**
     * Open protocols the user leads (also the hint source).
     *
     * @return Builder<FireIncident>
     */
    public static function open(User $user): Builder
    {
        return FireIncident::query()->where('leader_personnel_id', $user->personnel_id)->whereNull('finalized_at')->whereNull('archived_at');
    }

    public function data(User $user): array
    {
        if ($user->personnel_id === null) {
            return ['items' => [], 'total' => 0, 'reason' => 'no_profile', 'href' => self::ALL];
        }
        $own = FireIncident::query()->where('leader_personnel_id', $user->personnel_id)->whereNull('archived_at');

        return [
            'total' => (clone $own)->count(),
            'href' => self::ALL,
            'items' => $own->orderByDesc('started_at')->orderByDesc('id')->limit(self::ROWS)->get()->map(fn (FireIncident $i) => [
                'id' => $i->id,
                'label' => trim(($i->number ?? '').' '.($i->keyword ?? '')) ?: '#'.$i->id,
                'location' => $i->location,
                'started_at' => OrganizationTime::display($i->started_at),
                'open' => $i->finalized_at === null,
                'href' => FireProtocolType::link($i, $user),
            ])->all(),
        ];
    }
}
