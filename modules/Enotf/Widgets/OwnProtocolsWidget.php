<?php

namespace Modules\Enotf\Widgets;

use App\Dashboard\Widget;
use App\Models\User;
use App\Modules\BelongsToModule;
use Illuminate\Database\Eloquent\Builder;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\ProtocolCrew;
use Modules\Enotf\Support\LocalDateTimeFormatter;

/**
 * "Eigene eNOTF-Protokolle" (legacy OwnRecords::enotfProtocols): the newest protocols naming the account's personnel
 * record in their crew, continued by /me/protocols. Shown while the module is on, also without a record (empty state).
 */
final class OwnProtocolsWidget implements BelongsToModule, Widget
{
    public const ROWS = 5;

    public const ALL = '/me/protocols?source=enotf';

    public function module(): string
    {
        return 'enotf';
    }

    public function key(): string
    {
        return 'own_protocols';
    }

    public function area(): string
    {
        return 'main';
    }

    public function order(): int
    {
        return 36;
    }

    public function allowed(User $user): bool
    {
        return true;
    }

    /**
     * Not hidden, not withdrawn, the user's record in the crew (widget, hint source and /me/protocols).
     *
     * @return Builder<EnotfProtocol>
     */
    public static function own(User $user): Builder
    {
        return EnotfProtocol::query()->whereNull('hidden_at')->where('status', '!=', ProtocolStatus::Withdrawn)
            ->whereIn('id', ProtocolCrew::query()->select('enotf_protocol_id')->where('personnel_id', $user->personnel_id));
    }

    /** @return Builder<EnotfProtocol> */
    public static function open(User $user): Builder
    {
        return self::own($user)->where('status', ProtocolStatus::Open);
    }

    public static function href(EnotfProtocol $p): string
    {
        return '/enotf/p/'.rawurlencode($p->enr);
    }

    public function data(User $user): array
    {
        if ($user->personnel_id === null) {
            return ['items' => [], 'total' => 0, 'reason' => 'no_profile', 'href' => self::ALL];
        }
        $own = self::own($user);

        return [
            'total' => (clone $own)->count(),
            'href' => self::ALL,
            'items' => $own->orderByDesc('created_at')->orderByDesc('id')->limit(self::ROWS)->get()->map(fn (EnotfProtocol $p) => [
                'id' => $p->id,
                'label' => $p->enr,
                'vehicle' => $p->vehicle_transport_label ?: $p->vehicle_na_label,
                'created_at' => LocalDateTimeFormatter::at($p->created_at),
                'open' => $p->status === ProtocolStatus::Open,
                'href' => self::href($p),
            ])->all(),
        ];
    }
}
