<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Enums\QmStatus;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\ProtocolReviewLog;
use Modules\Enotf\Notifications\ProtocolType;
use Modules\Enotf\Support\LocalDateTimeFormatter;

/**
 * QM dialog: read with viewQm, write with review (real edivi.edit, never super-admin).
 * No crew session. Every change runs under the protocol row lock and writes a log entry; status and reopen also an
 * audit entry (ENR only) and a bell to the crew accounts (via personnel records). No patient data in log, audit or bell.
 */
class EnotfQmController extends Controller
{
    public function show(EnotfProtocol $protocol): Response
    {
        Gate::authorize('viewQm', EnotfProtocol::class);

        return Inertia::render('Enotf/Qm/Show', [
            'protocol' => [
                'enr' => $protocol->enr, 'status' => $protocol->status->value, 'qm_status' => $protocol->qm_status->value,
                'hidden' => $protocol->hidden_at !== null, 'billed' => $protocol->billed_at !== null,
                'vehicles' => array_values(array_filter([$protocol->vehicle_transport_label, $protocol->vehicle_na_label])),
                'created_at' => LocalDateTimeFormatter::at($protocol->created_at), 'released_at' => LocalDateTimeFormatter::at($protocol->released_at),
            ],
            'log' => $protocol->reviewLog()->orderByDesc('id')->limit(200)->get()->map(fn (ProtocolReviewLog $l) => [
                'id' => $l->id, 'kind' => $l->kind, 'from' => $l->from_status, 'to' => $l->to_status, 'text' => $l->text,
                'actor' => $l->actor_name, 'at' => LocalDateTimeFormatter::at($l->created_at),
            ])->all(),
            'can' => ['review' => Gate::allows('review', EnotfProtocol::class)],
        ]);
    }

    /** PATCH /enotf/qm/{enr}/status {qm_status: 0-4}; the same status again changes nothing. */
    public function status(Request $request, EnotfProtocol $protocol): JsonResponse
    {
        Gate::authorize('review', EnotfProtocol::class);
        $to = QmStatus::from((int) $request->validate(['qm_status' => ['required', 'integer', 'between:0,4']])['qm_status']);
        $user = $request->user();

        return DB::transaction(function () use ($protocol, $to, $user) {
            $locked = EnotfProtocol::query()->whereKey($protocol->id)->lockForUpdate()->firstOrFail();
            $from = $locked->qm_status;
            if ($from === $to) {
                return response()->json(['qm_status' => $to->value]);
            }
            $locked->forceFill(['qm_status' => $to])->save();
            $this->log($locked, $user, 'status', ['from_status' => $from->value, 'to_status' => $to->value]);
            AuditLogger::log($user, 'QM-Status geändert', $locked->enr, 'eNOTF', context: ['protocol_id' => $locked->id, 'from' => $from->value, 'to' => $to->value]);
            DB::afterCommit(fn () => ProtocolType::notifyCrew($locked, __('enotf.notification.qm_status', ['enr' => $locked->enr]), $user->id));

            return response()->json(['qm_status' => $to->value]);
        });
    }

    /** POST /enotf/qm/{enr}/comments {text}: plain text, shown escaped. */
    public function comment(Request $request, EnotfProtocol $protocol): JsonResponse
    {
        Gate::authorize('review', EnotfProtocol::class);
        $text = (string) $request->validate(['text' => ['required', 'string', 'max:2000']])['text'];

        DB::transaction(function () use ($protocol, $text, $request) {
            $locked = EnotfProtocol::query()->whereKey($protocol->id)->lockForUpdate()->firstOrFail();
            $this->log($locked, $request->user(), 'comment', ['text' => $text]);
        });

        return response()->json(['ok' => true], 201);
    }

    /** POST /enotf/qm/{enr}/reopen: a released, not billed protocol goes back to open; anything else (a repeat too) 409. */
    public function reopen(Request $request, EnotfProtocol $protocol): JsonResponse
    {
        Gate::authorize('review', EnotfProtocol::class);
        $user = $request->user();

        return DB::transaction(function () use ($protocol, $user) {
            $locked = EnotfProtocol::query()->whereKey($protocol->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ProtocolStatus::Released || $locked->billed_at !== null) {
                return response()->json(['message' => __($locked->billed_at !== null ? 'enotf.qm.reopen_billed' : 'enotf.qm.reopen_not_released'), 'status' => $locked->status->value], 409);
            }
            if ($locked->hidden_at !== null) {
                return response()->json(['message' => __('enotf.qm.reopen_hidden'), 'status' => $locked->status->value], 409);
            }
            $locked->forceFill([
                'status' => ProtocolStatus::Open, 'released_at' => null, 'released_by' => null, 'released_by_name' => null,
                'revision' => $locked->revision + 1,
            ])->save();
            $this->log($locked, $user, 'reopen');
            AuditLogger::log($user, 'Freigabe zurückgenommen', $locked->enr, 'eNOTF', context: ['protocol_id' => $locked->id]);
            DB::afterCommit(fn () => ProtocolType::notifyCrew($locked, __('enotf.notification.reopened', ['enr' => $locked->enr]), $user->id));

            return response()->json(['status' => ProtocolStatus::Open->value, 'revision' => $locked->revision]);
        });
    }

    /** @param array<string, mixed> $fields */
    private function log(EnotfProtocol $protocol, User $user, string $kind, array $fields = []): void
    {
        (new ProtocolReviewLog)->forceFill($fields + [
            'enotf_protocol_id' => $protocol->id, 'kind' => $kind,
            'actor_user_id' => $user->id, 'actor_name' => mb_substr($user->displayName(), 0, 255),
        ])->save();
    }
}
