<?php

namespace Modules\FireTab\Support;

use App\Models\Vehicle;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\FireTab\Models\FireStatusQueue;

/**
 * FMS status changes for FiveM (fire_status_queue). At-most-once like the legacy: each row goes to exactly one consumer.
 * A claimed row that is never marked delivered (consumer crashed) is not handed out again; the prune command drops
 * undelivered rows after 1 h (status is volatile, a stale status is worse than a lost one).
 */
final class StatusQueue
{
    public static function push(Vehicle $vehicle, string $code, ?string $incidentNumber): void
    {
        (new FireStatusQueue)->forceFill([
            'vehicle_id' => $vehicle->id, 'vehicle_name' => $vehicle->name,
            'incident_number' => $incidentNumber, 'status' => $code, 'created_at' => now(),
        ])->save();
    }

    /**
     * Atomically claims up to $limit open rows. The conditional UPDATE (`claim_token IS NULL`) is the lock: a parallel
     * claim re-checks it after the row lock (MariaDB) or the writer lock (SQLite), so two claims never share a row.
     * Two steps instead of `UPDATE … ORDER BY … LIMIT` / `IN (SELECT … LIMIT)`: neither works on both SQLite and MariaDB.
     *
     * @return Collection<int, FireStatusQueue> every row carries the same claim_token
     */
    public static function claim(int $limit = 100): Collection
    {
        $ids = FireStatusQueue::query()->whereNull('delivered_at')->whereNull('claim_token')->orderBy('id')->limit($limit)->pluck('id');
        if ($ids->isEmpty()) {
            return new Collection;
        }

        $token = Str::random(40);
        FireStatusQueue::query()->whereIn('id', $ids)->whereNull('claim_token')->whereNull('delivered_at')
            ->update(['claim_token' => $token, 'claimed_at' => now()]);

        return FireStatusQueue::query()->where('claim_token', $token)->orderBy('id')->get();
    }

    /**
     * Claims and marks up to $limit rows at once, in the legacy answer shape (status-poll and EMD sync).
     *
     * @return list<array{vehicle_name: string, status: string, incident_number: ?string, timestamp: ?string}>
     */
    public static function deliver(int $limit = 100): array
    {
        $rows = self::claim($limit);
        if ($rows->isNotEmpty()) {
            self::markDelivered((string) $rows->first()->claim_token);
        }

        return $rows->map(fn (FireStatusQueue $r) => [
            'vehicle_name' => $r->vehicle_name,
            'status' => $r->status,
            'incident_number' => $r->incident_number,
            'timestamp' => OrganizationTime::display($r->created_at),
        ])->values()->all();
    }

    public static function markDelivered(string $token): int
    {
        return FireStatusQueue::query()->where('claim_token', $token)->whereNull('delivered_at')->update(['delivered_at' => now()]);
    }
}
