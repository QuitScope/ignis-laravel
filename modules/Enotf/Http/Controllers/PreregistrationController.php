<?php

namespace Modules\Enotf\Http\Controllers;

use App\Discord\DiscordWebhook;
use App\Discord\Embeds;
use App\Discord\WebhookKind;
use App\Http\Controllers\Controller;
use App\Models\Poi;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Enotf\Http\Middleware\EnsureHospitalSession;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\Preregistration;
use Modules\Enotf\Support\LocalDateTimeFormatter;

/**
 * Pre-registration and arrival board. The crew of the protocol's vehicle (policy handover)
 * registers the patient at a hospital (setting enotf.prereg_enabled) and can cancel; Discord gets urgency, arrival,
 * vehicle and hospital, never a diagnosis or patient data. The board is for a clinic device of that hospital only
 * (EnsureHospitalSession); reading never changes data (legacy deactivated entries on every poll), the clinic marks
 * seen/arrived by PUT. Entries leave the board at expires_at (60 min after the ETA) and are pruned after 24 h.
 */
class PreregistrationController extends Controller
{
    /** GET /enotf/p/{enr}/prereg: pre-registrations and clinic code of one protocol (crew). */
    public function index(Request $request, EnotfProtocol $protocol, Settings $settings): Response
    {
        $this->authorize('handover', $protocol);

        return Inertia::render('Enotf/Prereg', [
            'protocol' => ['enr' => $protocol->enr, 'vehicles' => array_values(array_filter([$protocol->vehicle_transport_label, $protocol->vehicle_na_label]))],
            'prereg_enabled' => (bool) $settings->get('enotf.prereg_enabled', true),
            'hospitals' => Poi::query()->hospitals()->orderBy('name')->get(['id', 'name'])->map(fn (Poi $p) => ['id' => $p->id, 'name' => $p->name])->all(),
            'preregistrations' => $this->preregistrations($protocol),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function preregistrations(EnotfProtocol $protocol): array
    {
        $rows = Preregistration::query()->where('enotf_protocol_id', $protocol->id)->orderByDesc('id')->get();
        $names = Poi::query()->whereIn('id', $rows->pluck('poi_id'))->pluck('name', 'id');

        return $rows->map(fn (Preregistration $r) => self::row($r, $protocol) + ['hospital' => $names[$r->poi_id] ?? null])->all();
    }

    public function store(Request $request, EnotfProtocol $protocol, Settings $settings): RedirectResponse
    {
        $this->authorize('handover', $protocol);
        abort_unless((bool) $settings->get('enotf.prereg_enabled', true), 404);
        $data = $request->validate([
            'poi_id' => ['required', 'integer', Rule::exists('pois', 'id')->where('is_active', true)->whereIn('type', Poi::HOSPITAL_TYPES)],
            'priority' => ['required', 'integer', 'in:0,1,2'],
            'eta_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $eta = isset($data['eta_minutes']) ? now()->addMinutes((int) $data['eta_minutes']) : null;
        $prereg = (new Preregistration)->forceFill([
            'enotf_protocol_id' => $protocol->id, 'poi_id' => $data['poi_id'], 'status' => 'open', 'priority' => $data['priority'], 'eta' => $eta,
            'note' => HospitalPortalController::note($data['note'] ?? null), 'created_by_user_id' => $request->user()->id,
            'expires_at' => ($eta ?? now())->copy()->addMinutes(60),
        ]);
        $prereg->save();
        // Never the note (free text, may name a diagnosis) or anything else of the patient.
        DiscordWebhook::dispatch(WebhookKind::EnotfPrereg, Embeds::prereg([
            'priority' => $prereg->priority,
            'arrival' => $eta?->setTimezone((string) config('ignis.timezone'))->toDateTimeString(),
            'vehicle' => $protocol->vehicle_transport_label ?: $protocol->vehicle_na_label,
            'hospital' => Poi::query()->whereKey($prereg->poi_id)->value('name'),
        ]));

        return back();
    }

    public function cancel(EnotfProtocol $protocol, int $prereg): RedirectResponse
    {
        $this->authorize('handover', $protocol);
        Preregistration::query()->where('enotf_protocol_id', $protocol->id)->findOrFail($prereg);
        Preregistration::query()->whereKey($prereg)->whereIn('status', Preregistration::ACTIVE)->update(['status' => 'cancelled', 'updated_at' => now()]);

        return back();
    }

    /** GET /enotf/schnittstelle: the arrival board of the signed-in clinic device. */
    public function board(Request $request): Response
    {
        $poi = EnsureHospitalSession::poi($request);

        return Inertia::render('Enotf/Portal/Board', ['hospital' => ['id' => $poi->id, 'name' => $poi->name], 'preregistrations' => self::active($poi)]);
    }

    /** GET /enotf/schnittstelle/prereg: the board's poll (15 s), read only. */
    public function poll(Request $request): JsonResponse
    {
        return response()->json(['preregistrations' => self::active(EnsureHospitalSession::poi($request))]);
    }

    /** PUT /enotf/schnittstelle/prereg/{id}: the clinic marks seen or arrived; only its own, still active entries. */
    public function mark(Request $request, int $prereg): JsonResponse
    {
        $poi = EnsureHospitalSession::poi($request);
        $data = $request->validate(['status' => ['required', 'in:seen,arrived']]);
        $row = Preregistration::query()->where('poi_id', $poi->id)->active()->findOrFail($prereg);
        $row->forceFill(['status' => $data['status']])->save();

        return response()->json(self::row($row, $row->protocol));
    }

    /** @return list<array<string, mixed>> */
    private static function active(Poi $poi): array
    {
        return Preregistration::query()->where('poi_id', $poi->id)->active()
            ->whereHas('protocol', fn ($q) => $q->where('status', '!=', 'withdrawn')->whereNull('hidden_at'))
            ->with('protocol:id,enr,vehicle_transport_label,vehicle_na_label')
            ->orderByRaw('eta IS NULL')->orderBy('eta')->orderBy('id')->get()
            ->map(fn (Preregistration $r) => self::row($r, $r->protocol))->values()->all();
    }

    /** @return array<string, mixed> */
    private static function row(Preregistration $r, ?EnotfProtocol $protocol): array
    {
        return [
            'id' => $r->id, 'status' => $r->status, 'priority' => $r->priority, 'eta' => LocalDateTimeFormatter::at($r->eta), 'note' => $r->note,
            'enr' => $protocol?->enr, 'vehicle' => $protocol === null ? null : ($protocol->vehicle_transport_label ?: $protocol->vehicle_na_label),
            'created_at' => LocalDateTimeFormatter::at($r->created_at),
        ];
    }
}
