<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ServiceNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Live "is this service number free" checks. Answers keep the legacy shapes (errors are 200s with an `error` key). */
class ServiceNumberController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        $n = $request->input('dienstnr');
        $number = is_string($n) ? trim($n) : '';
        if ($number === '') {
            return response()->json(['error' => 'Keine Dienstnummer angegeben']);
        }
        if (! ServiceNumber::isValid($number)) {
            return response()->json(['error' => 'Ungültiges Format für Dienstnummer. Muss mindestens eine Zahl enthalten.']);
        }

        $available = ServiceNumber::isAvailable($number, $request->integer('exclude_id') ?: null);

        return response()->json([
            'available' => $available,
            'dienstnr' => $number,
            'message' => $available ? 'Dienstnummer verfügbar' : 'Dienstnummer bereits vergeben',
        ]);
    }

    /** Plain text `exists|not_exists|error` for the old form-post callers. */
    public function checkLegacy(Request $request): Response
    {
        $n = $request->input('dienstnr');
        $number = is_string($n) ? trim($n) : '';
        $text = ! ServiceNumber::isValid($number) ? 'error'
            : (ServiceNumber::isAvailable($number, $request->integer('exclude_id') ?: null) ? 'not_exists' : 'exists');

        return response($text, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
