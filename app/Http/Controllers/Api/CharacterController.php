<?php

namespace App\Http\Controllers\Api;

use App\Models\FiveMCharacter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST api/character/identify (ef_bridge server/main.lua; `.php` reaches it too). Middleware: api.key,
 * throttle:character-identify. `session_id` is the page's one-time token (FiveMCharacter), never a session id.
 * Body with only the key -> 422: ef_bridge's connection test (server/admin.lua) expects exactly that.
 * Answers: linked 200, same data again 200, other data 409, unknown/expired 404.
 * Nothing is logged but an exception's class: no token, no character name (a QueryException message has the bindings).
 */
final class CharacterController
{
    public function identify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string', 'min:8', 'max:128'],
            'char_name' => ['required', 'string', 'min:1', 'max:100'],
            'char_job' => ['required', 'string', 'min:1', 'max:50'],
            'char_id' => ['nullable', 'integer', 'min:1'],
        ]);
        try {
            return $this->redeem($data);
        } catch (Throwable $e) {
            Log::error('Character identify failed', ['exception' => $e::class]);

            return response()->json(['success' => false], 500);
        }
    }

    /** @param array<string, mixed> $data */
    private function redeem(array $data): JsonResponse
    {
        $values = ['char_name' => $data['char_name'], 'char_job' => $data['char_job'], 'char_id' => isset($data['char_id']) ? (int) $data['char_id'] : null];
        $hash = FiveMCharacter::hashToken($data['session_id']);

        $linked = FiveMCharacter::query()->where('token_hash', $hash)->whereNull('identified_at')->where('expires_at', '>', now())
            ->update($values + ['identified_at' => now(), 'updated_at' => now()]);
        if ($linked === 0) {
            $row = FiveMCharacter::query()->where('token_hash', $hash)
                ->where('identified_at', '>', now()->subHours(FiveMCharacter::VALID_HOURS))->first();
            if ($row === null) {
                return response()->json(['success' => false, 'message' => 'Token unbekannt oder abgelaufen'], 404);
            }
            if ([$row->char_name, $row->char_job, $row->char_id] !== array_values($values)) {
                return response()->json(['success' => false, 'message' => 'Token bereits mit einem anderen Charakter verknüpft'], 409);
            }
        }

        return response()->json(['success' => true, 'message' => 'Charakter-Daten erfolgreich gesetzt', 'char_name' => $values['char_name'], 'char_job' => $values['char_job']]);
    }
}
