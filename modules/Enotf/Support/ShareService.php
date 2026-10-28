<?php

namespace Modules\Enotf\Support;

use App\Models\User;
use App\Models\Vehicle;
use App\Realtime\Realtime;
use App\Support\AuditLogger;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\MedicationGiven;
use Modules\Enotf\Models\ShareRequest;
use Modules\Enotf\Models\VitalSign;
use Modules\Enotf\Notifications\ProtocolType;

/**
 * Sharing protocols between vehicles. State machine
 * pending -> accepted | rejected | cancelled | expired; every transition is ONE conditional
 * `UPDATE … WHERE id = ? AND status = 'pending' [AND …]`, so of two concurrent accepts (or accept vs. reject) exactly one
 * changes the row and the other gets 409 (no check-then-act; works on SQLite too). Expiry (30 min) is a read condition
 * (accept/reject need expires_at > now) and enotf:share-expire marks the rest `expired`.
 * The payload is an allowlisted copy (FieldSchema::shareKeys) taken at request time; accepting merges it into an OPEN
 * protocol of the accepting crew's own vehicle (or a new one) through ProtocolMerger under the row lock, so revision and
 * field_revisions stay right. Non-empty target fields are kept unless `overwrite`. Vital signs and given medication are
 * own rows (no revision): the source's active rows are copied into the target at accept time, each row once (a row
 * equal to one the target already has is skipped), so the target's own rows stay and a second share adds no duplicates.
 */
final class ShareService
{
    public const TTL_MINUTES = 30;

    public function __construct(private ProtocolMerger $merger, private ProtocolCreator $creator) {}

    public function request(User $user, EnotfProtocol $protocol, Vehicle $from, Vehicle $to): ShareRequest
    {
        // ponytail: check-then-insert; two simultaneous identical offers can both land (harmless, one gets 409 on answer)
        abort_if(ShareRequest::query()->where('protocol_id', $protocol->id)->where('to_vehicle_id', $to->id)
            ->where('status', 'pending')->where('expires_at', '>', now())->exists(), 409, __('enotf.share.duplicate'));
        $payload = array_filter(
            array_intersect_key(ProtocolMerger::values($protocol), array_flip(FieldSchema::shareKeys())),
            fn (mixed $v) => $v !== null && $v !== [],
        );
        $share = (new ShareRequest)->forceFill([
            'protocol_id' => $protocol->id,
            'from_vehicle_id' => $from->id,
            'to_vehicle_id' => $to->id,
            'requested_by_user_id' => $user->id,
            'status' => 'pending',
            'payload' => $payload,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);
        $share->save();
        VehiclePulse::send($to->id);
        AuditLogger::log($user, 'Protokoll zum Teilen angeboten', $protocol->enr, 'eNOTF', context: ['share_request_id' => $share->id, 'protocol_id' => $protocol->id, 'to_vehicle_id' => $to->id]);
        ProtocolType::notifyVehicleCrew($to->id, __('enotf.share.notification', ['enr' => $protocol->enr, 'vehicle' => $from->identifier]), '/enotf/overview');

        return $share;
    }

    /**
     * Accept into $target (an open protocol of $vehicle) or a new protocol. Aborts 403 (foreign target), 409 (target or source
     * not open any more, request no longer pending/expired); all inside one transaction, so a refused merge leaves the request pending.
     *
     * @return array{protocol: EnotfProtocol, applied: list<string>, kept: list<string>}
     */
    public function accept(User $user, ShareRequest $share, Vehicle $vehicle, ?EnotfProtocol $target, bool $overwrite): array
    {
        return DB::transaction(function () use ($user, $share, $vehicle, $target, $overwrite) {
            if ($target !== null) {
                $target = EnotfProtocol::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
                abort_unless($target->hasVehicle($vehicle->id), 403);
                abort_unless($target->isOpen(), 409, __('enotf.protocol.closed'));
                abort_unless($user->can('share', $target), 403);
            }
            $source = EnotfProtocol::query()->find($share->protocol_id);
            abort_if($source === null || $source->status === ProtocolStatus::Withdrawn || $source->hidden_at !== null, 409, __('enotf.share.gone'));
            $won = $this->transition($share, 'accepted', $user, fn (Builder $q) => $q->where('to_vehicle_id', $vehicle->id)->where('expires_at', '>', now()));
            abort_unless($won, 409, __('enotf.share.gone'));

            $target ??= $this->creator->create($this->creator->nextFreeEnr($source->enr), ProtocolCreator::slot($vehicle) === 'na' ? 1 : 0, $user, $vehicle);
            $current = ProtocolMerger::values($target);
            $payload = array_intersect_key($share->payload, array_flip(FieldSchema::shareKeys()));
            $fill = array_filter($payload, fn (mixed $v, string $k) => $overwrite || ($current[$k] ?? null) === null || $current[$k] === [], ARRAY_FILTER_USE_BOTH);
            $applied = [];
            if ($fill !== []) {
                $values = FieldSchema::validate($fill)['values'];
                $applied = $values === [] ? [] : $this->merger->apply($target, $target->revision, $values)['applied'];
            }
            $rows = $this->copyRows($source, $target);
            ShareRequest::query()->whereKey($share->id)->update(['target_protocol_id' => $target->id]);
            AuditLogger::log($user, 'Geteiltes Protokoll übernommen', $target->enr, 'eNOTF', context: ['share_request_id' => $share->id, 'protocol_id' => $target->id, 'source_protocol_id' => $source->id, 'fields' => count($applied), 'rows' => $rows]);

            return ['protocol' => $target, 'applied' => $applied, 'kept' => array_values(array_diff(array_keys($payload), $applied))];
        });
    }

    private function copyRows(EnotfProtocol $source, EnotfProtocol $target): int
    {
        $copied = 0;
        $tables = [
            [VitalSign::class, ['parameter', 'value', 'value_text', 'unit', 'measured_at']],
            [MedicationGiven::class, ['medication_id', 'name', 'dose', 'unit', 'route', 'given_at']],
        ];
        foreach ($tables as [$model, $keys]) {
            $signature = fn (Model $row): string => json_encode(array_map(fn (string $key) => $row->getRawOriginal($key), $keys), JSON_THROW_ON_ERROR);
            $have = $model::query()->where('enotf_protocol_id', $target->id)->whereNull('deleted_at')->get()->countBy($signature)->all();
            foreach ($model::query()->where('enotf_protocol_id', $source->id)->whereNull('deleted_at')->orderBy('id')->get() as $row) {
                $sig = $signature($row);
                if (($have[$sig] ?? 0) > 0) {
                    $have[$sig]--;

                    continue;
                }
                $copy = $row->replicate(['enotf_protocol_id', 'legacy', 'deleted_by_user_id']);
                $copy->forceFill(['enotf_protocol_id' => $target->id])->save();
                $copied++;
            }
        }
        if ($copied > 0) {
            foreach (array_unique(array_filter([$target->vehicle_transport_id, $target->vehicle_na_id])) as $vehicleId) {
                Realtime::pulse('enotf.vehicle.'.$vehicleId, 'rows');
            }
        }

        return $copied;
    }

    public function reject(User $user, ShareRequest $share, Vehicle $vehicle): bool
    {
        return $this->transition($share, 'rejected', $user, fn (Builder $q) => $q->where('to_vehicle_id', $vehicle->id)->where('expires_at', '>', now()));
    }

    public function cancel(User $user, ShareRequest $share): bool
    {
        return $this->transition($share, 'cancelled', $user, fn (Builder $q) => $q->where('requested_by_user_id', $user->id));
    }

    public function expire(): int
    {
        $expired = ShareRequest::query()->where('status', 'pending')->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'resolved_at' => now(), 'payload' => '[]']);
        ShareRequest::query()->where('status', '!=', 'pending')->where('resolved_at', '<', now()->subDay())->delete();

        return $expired;
    }

    /**
     * Terminal transition; the payload (patient data) is emptied in the same UPDATE. accept() merges from the copy it
     * loaded before.
     *
     * @param  Closure(Builder<ShareRequest>): mixed  $where
     */
    private function transition(ShareRequest $share, string $to, User $user, Closure $where): bool
    {
        $query = ShareRequest::query()->whereKey($share->id)->where('status', 'pending');
        $where($query);

        if ($query->update(['status' => $to, 'resolved_at' => now(), 'resolved_by_user_id' => $user->id, 'payload' => '[]']) !== 1) {
            return false;
        }
        VehiclePulse::send($share->from_vehicle_id, $share->to_vehicle_id);

        return true;
    }
}
