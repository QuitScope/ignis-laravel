<?php

namespace Modules\Federation\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Federation\Models\FederationLink;
use Modules\Federation\Support\FederationInstance;
use Modules\Federation\Support\Pairing;
use Modules\Federation\Support\PeerHttp;

/**
 * POST /api/federation/pair (side A, unsigned, throttle 10/min): the one-time code is checked and consumed before any
 * work; a half pairing (never confirmed) of the same instance is replaced, a confirmed one answers 409. The code and
 * both secrets never appear in the response (except our new secret_for_you), the audit or a log.
 */
class PairController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $code = $request->json('code');
        if (! is_string($code) || $code === '' || ! Pairing::consume($code)) {
            return $this->fail('invalid_code', 403);
        }

        $v = Validator::make($request->json()->all(), [
            'v' => ['required', 'in:2'],
            'instance_id' => ['required', 'string', 'uuid'],
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'string', 'max:255'],
            'secret_for_you' => ['required', 'string', 'min:32', 'max:255'],
        ]);
        if ($v->fails()) {
            return $this->fail('invalid_request', 422);
        }
        /** @var array{instance_id: string, name: string, url: string, secret_for_you: string} $in */
        $in = $v->validated();
        $url = rtrim($in['url'], '/');
        if ($in['instance_id'] === FederationInstance::instanceId()) {
            return $this->fail('invalid_request', 422);
        }
        if (($error = PeerHttp::check($url)) !== null) {
            return $this->fail($error, 422);
        }

        $secretForThem = Str::random(64);
        $link = DB::transaction(function () use ($in, $url, $secretForThem) {
            $link = FederationLink::query()->where('instance_id', $in['instance_id'])->lockForUpdate()->first() ?? new FederationLink;
            if ($link->exists && $link->confirmed_at !== null) {
                return null;
            }
            $link->forceFill(['instance_id' => $in['instance_id']] + Pairing::fresh(trim($in['name']), $url, $in['secret_for_you'], $secretForThem))->save();

            return $link;
        });
        if ($link === null) {
            return $this->fail('already_linked', 409);
        }

        AuditLogger::log(null, 'Instanz gekoppelt', $link->name, 'Federation', global: true, context: ['link_id' => $link->id, 'instance_id' => $link->instance_id]);

        return response()->json(['success' => true, 'v' => 2, 'instance_id' => FederationInstance::instanceId(), 'name' => Pairing::name(), 'secret_for_you' => $secretForThem]);
    }

    private function fail(string $code, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'error' => $code], $status);
    }
}
