<?php

namespace Modules\Enotf\Widgets;

use App\Dashboard\Widget;
use App\Models\User;
use App\Modules\BelongsToModule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Models\EnotfProtocol;

/**
 * KPI "eNOTF-Protokolle offen" (legacy Overview::openProtocols): all visible protocols the crew has not released yet,
 * the oldest of them, and this week's share of released ones (since Monday 0:00, organisation time). For QM readers
 * (viewQm) only; the admin-wide hint reads the same figures.
 */
final class OpenProtocolsKpiWidget implements BelongsToModule, Widget
{
    public const HREF = '/enotf/admin?segment=open';

    public function module(): string
    {
        return 'enotf';
    }

    public function key(): string
    {
        return 'enotf_open';
    }

    public function area(): string
    {
        return 'kpi';
    }

    public function order(): int
    {
        return 30;
    }

    public function allowed(User $user): bool
    {
        return Gate::forUser($user)->allows('viewQm', EnotfProtocol::class);
    }

    /** @return array{open: int, oldest_at: ?string, week: int, week_released: int, href: string} */
    public function data(User $user): array
    {
        return self::figures();
    }

    /**
     * Counts over every protocol: callers check viewQm first. Once per request (widget and hint), memoised on the
     * request like IncidentStats::current().
     *
     * @return array{open: int, oldest_at: ?string, week: int, week_released: int, href: string}
     */
    public static function figures(): array
    {
        $memo = request()->attributes;
        if (! $memo->has('dashboard.enotf_open')) {
            $memo->set('dashboard.enotf_open', self::compute());
        }

        return $memo->get('dashboard.enotf_open');
    }

    /** @return array{open: int, oldest_at: ?string, week: int, week_released: int, href: string} */
    private static function compute(): array
    {
        $visible = fn (): Builder => EnotfProtocol::query()->whereNull('hidden_at');
        $open = $visible()->where('status', ProtocolStatus::Open);
        $oldest = (clone $open)->min('created_at');
        $monday = CarbonImmutable::now((string) config('ignis.timezone'))->startOfWeek(CarbonImmutable::MONDAY)->utc();
        $week = $visible()->where('status', '!=', ProtocolStatus::Withdrawn)->where('created_at', '>=', $monday)->toBase()
            ->selectRaw("COUNT(*) AS total, COALESCE(SUM(CASE WHEN status = 'released' THEN 1 ELSE 0 END), 0) AS released")->first();

        return [
            'open' => $open->count(),
            'oldest_at' => $oldest === null ? null : CarbonImmutable::make($oldest)?->toIso8601String(),
            'week' => (int) ($week->total ?? 0),
            'week_released' => (int) ($week->released ?? 0),
            'href' => self::HREF,
        ];
    }
}
