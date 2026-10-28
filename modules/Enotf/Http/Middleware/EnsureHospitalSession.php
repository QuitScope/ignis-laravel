<?php

namespace Modules\Enotf\Http\Middleware;

use App\Models\Poi;
use Closure;
use Illuminate\Http\Request;
use Modules\Enotf\Models\HospitalAccessCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clinic device: the session key `enotf_hospital` = [poi_id, fingerprint of the code hash],
 * set by the portal login. Valid only while the POI is an active hospital and its access code is unchanged (rotation or
 * deletion signs every device out). No account, no crew context: this grants nothing outside the portal routes, and an
 * account or crew session grants nothing here. The POI is handed to the controller as request attribute `hospital_poi`.
 */
final class EnsureHospitalSession
{
    public const KEY = 'enotf_hospital';

    public function handle(Request $request, Closure $next): Response
    {
        $state = $request->session()->get(self::KEY);
        $poi = is_array($state) ? Poi::query()->hospitals()->find($state['poi_id'] ?? 0) : null;
        $code = $poi === null ? null : HospitalAccessCode::query()->where('poi_id', $poi->id)->first();
        if ($poi === null || $code === null || ! hash_equals($code->fingerprint(), (string) ($state['fingerprint'] ?? ''))) {
            $request->session()->forget(self::KEY);

            return $request->expectsJson() ? response()->json(['message' => __('enotf.portal.signed_out')], 403) : redirect('/enotf/schnittstelle/login');
        }
        $request->attributes->set('hospital_poi', $poi);

        return $next($request);
    }

    public static function poi(Request $request): Poi
    {
        $poi = $request->attributes->get('hospital_poi');
        abort_unless($poi instanceof Poi, 403);

        return $poi;
    }
}
