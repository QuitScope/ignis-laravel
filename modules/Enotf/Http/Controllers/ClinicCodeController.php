<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Models\ClinicCode;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Support\LocalDateTimeFormatter;
use Modules\Enotf\Support\SecretCode;

/**
 * Clinic code (legacy gave every ENR to whoever held any code, without PIN). The crew of the
 * vehicle makes a code for ONE protocol (policy handover); it reads that protocol only, read only, for 1 h. Code format
 * `<row id>-<10 secret characters>` (50 bits): the id finds the row, the secret is checked against its hash (timing
 * safe), so a wrong code tells nothing about which protocols exist. Redemption 10/min per IP. The session key
 * `enotf_clinic` holds [code id, protocol id] only; it is no account and no crew context.
 */
class ClinicCodeController extends Controller
{
    public const KEY = 'enotf_clinic';

    /** POST /enotf/p/{enr}/clinic-code: a new code replaces the protocol's old ones; the plain code is in this answer only. */
    public function store(Request $request, EnotfProtocol $protocol): JsonResponse
    {
        $this->authorize('handover', $protocol);
        $secret = SecretCode::generate(ClinicCode::SECRET_LENGTH);
        ClinicCode::query()->where('enotf_protocol_id', $protocol->id)->delete();
        $code = (new ClinicCode)->forceFill([
            'enotf_protocol_id' => $protocol->id, 'code_hash' => Hash::make($secret), 'expires_at' => now()->addHour(), 'created_by_user_id' => $request->user()->id,
        ]);
        $code->save();

        return response()->json(['code' => $code->id.'-'.$secret, 'expires_at' => LocalDateTimeFormatter::at($code->expires_at)], 201);
    }

    public function form(): Response
    {
        return Inertia::render('Enotf/Portal/Klinikcode');
    }

    public function redeem(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);
        $raw = strtoupper((string) preg_replace('/\s+/', '', $data['code']));
        $parts = preg_match('/^(\d{1,18})-([A-Z0-9]{1,32})$/D', $raw, $m) === 1 ? $m : null;
        $code = $parts === null ? null : ClinicCode::query()->where('expires_at', '>', now())->find((int) $parts[1]);
        $ok = Hash::check($parts[2] ?? '-', $code->code_hash ?? Hash::make('-'));
        if ($code === null || ! $ok) {
            throw ValidationException::withMessages(['code' => __('enotf.clinic_code.invalid')]);
        }
        $code->forceFill(['last_used_at' => now()])->save();
        $request->session()->regenerate();
        $request->session()->put(self::KEY, ['code_id' => $code->id, 'protocol_id' => $code->enotf_protocol_id]);

        return redirect('/enotf/schnittstelle/protocol');
    }

    /** GET /enotf/schnittstelle/protocol: the one bound protocol, read only (print view without crew links). */
    public function show(Request $request): Response|RedirectResponse
    {
        $state = $request->session()->get(self::KEY);
        $code = is_array($state) ? ClinicCode::query()->where('expires_at', '>', now())->find($state['code_id'] ?? 0) : null;
        if ($code === null || $code->enotf_protocol_id !== ($state['protocol_id'] ?? null)) {
            $request->session()->forget(self::KEY);

            return redirect('/enotf/schnittstelle/klinikcode')->withErrors(['code' => __('enotf.clinic_code.expired')]);
        }
        $protocol = EnotfProtocol::query()->whereKey($code->enotf_protocol_id)->where('status', '!=', ProtocolStatus::Withdrawn)->whereNull('hidden_at')->firstOrFail();

        return Inertia::render('Enotf/Print', PrintController::props($protocol) + ['clinic' => true]);
    }
}
