<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Poi;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Enotf\Models\HospitalAccessCode;
use Modules\Enotf\Support\SecretCode;

/**
 * /settings/pois/{poi}/access-code (poi.manage): the server makes a 16-character code (80 bits), stores only
 * its hash and answers the plain code once in this JSON response (never in a flash, log, audit or list). Rotating replaces
 * the hash: the old code and every device signed in with it are out. DELETE revokes the code.
 */
class AccessCodeController extends Controller
{
    public function rotate(Request $request, Poi $poi): JsonResponse
    {
        $this->authorize('poi.manage');
        abort_unless(in_array($poi->type, Poi::HOSPITAL_TYPES, true), 404);
        $plain = SecretCode::generate(16);
        (HospitalAccessCode::query()->where('poi_id', $poi->id)->first() ?? new HospitalAccessCode)->forceFill([
            'poi_id' => $poi->id, 'code_hash' => Hash::make($plain), 'rotated_at' => now(), 'created_by_user_id' => $request->user()->id, 'last_used_at' => null,
        ])->save();
        AuditLogger::log($request->user(), 'Klinik-Zugangscode erneuert', $poi->name, 'eNOTF', false, ['poi_id' => $poi->id]);

        return response()->json(['code' => SecretCode::display($plain)]);
    }

    public function destroy(Request $request, Poi $poi): JsonResponse
    {
        $this->authorize('poi.manage');
        HospitalAccessCode::query()->where('poi_id', $poi->id)->delete();
        AuditLogger::log($request->user(), 'Klinik-Zugangscode entfernt', $poi->name, 'eNOTF', false, ['poi_id' => $poi->id]);

        return response()->json(['ok' => true]);
    }
}
