<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Poi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Enotf\Http\Middleware\EnsureHospitalSession;
use Modules\Enotf\Models\HospitalAccessCode;
use Modules\Enotf\Models\HospitalDepartment;
use Modules\Enotf\Support\SecretCode;

/**
 * Clinic portal /enotf/schnittstelle/*: a clinic device signs in with its hospital's
 * access code (no account; 5/min per IP, new session id, sign-out by POST). It may set the availability of its own
 * hospital's departments only: the department is looked up inside the session POI (another hospital's: 404).
 */
class HospitalPortalController extends Controller
{
    public function showLogin(Request $request): Response|RedirectResponse
    {
        if (is_array($request->session()->get(EnsureHospitalSession::KEY))) {
            return redirect('/enotf/schnittstelle');
        }

        return Inertia::render('Enotf/Portal/Login', [
            'hospitals' => Poi::query()->hospitals()->orderBy('name')->get(['id', 'name'])->map(fn (Poi $p) => ['id' => $p->id, 'name' => $p->name])->all(),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['poi_id' => ['required', 'integer'], 'code' => ['required', 'string', 'max:255']]);
        $poi = Poi::query()->hospitals()->find($data['poi_id']);
        $code = $poi === null ? null : HospitalAccessCode::query()->where('poi_id', $poi->id)->first();
        $hash = $code->code_hash ?? Hash::make('-');
        $ok = Hash::check(SecretCode::normalize($data['code']), $hash) || Hash::check(trim($data['code']), $hash);
        if ($code === null || ! $ok) {
            throw ValidationException::withMessages(['code' => __('enotf.portal.invalid')]);
        }
        $code->forceFill(['last_used_at' => now()])->save();
        $request->session()->regenerate();
        $request->session()->put(EnsureHospitalSession::KEY, ['poi_id' => $code->poi_id, 'fingerprint' => $code->fingerprint()]);

        return redirect('/enotf/schnittstelle');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(EnsureHospitalSession::KEY);
        $request->session()->regenerate();

        return redirect('/enotf/schnittstelle/login');
    }

    public function availability(Request $request): Response
    {
        $poi = EnsureHospitalSession::poi($request);

        return Inertia::render('Enotf/Portal/Availability', [
            'hospital' => ['id' => $poi->id, 'name' => $poi->name],
            'departments' => self::departments($poi->id),
            'statuses' => HospitalDepartment::STATUSES,
        ]);
    }

    public function updateAvailability(Request $request, int $department): JsonResponse
    {
        $poi = EnsureHospitalSession::poi($request);
        $row = HospitalDepartment::query()->where('poi_id', $poi->id)->findOrFail($department);
        $data = $request->validate(['status' => ['required', Rule::in(HospitalDepartment::STATUSES)], 'note' => ['nullable', 'string', 'max:255']]);
        $row->setStatus($data['status'], self::note($data['note'] ?? null), __('enotf.portal.by_device'));

        return response()->json($row->toView());
    }

    /** @return list<array<string, mixed>> */
    public static function departments(int $poiId): array
    {
        return HospitalDepartment::query()->where('poi_id', $poiId)->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (HospitalDepartment $d) => $d->toView())->values()->all();
    }

    public static function note(?string $note): ?string
    {
        return $note === null || trim($note) === '' ? null : trim($note);
    }
}
