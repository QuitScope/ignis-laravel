<?php

namespace Modules\Federation\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Federation\Models\FederationLink;
use Modules\Federation\Support\FederationInstance;
use Modules\Federation\Support\Pairing;

/** GET /api/federation/handshake: a signed ping (VerifyPeerSignature has confirmed the link); capabilities like legacy. */
class HandshakeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var FederationLink $link */
        $link = $request->attributes->get('federation_link');

        return response()->json([
            'success' => true,
            'version' => 2,
            'instance_id' => FederationInstance::instanceId(),
            'name' => Pairing::name(),
            'capabilities' => array_keys(array_filter(['personnel' => $link->provide_personnel, 'enotf' => $link->provide_enotf, 'fire' => $link->provide_fire])),
        ]);
    }
}
