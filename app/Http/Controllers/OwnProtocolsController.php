<?php

namespace App\Http\Controllers;

use App\Support\OwnProtocols;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** `GET /me/protocols`: own FireTab and eNOTF records in one plain list; `?source=` narrows it to one source. */
class OwnProtocolsController extends Controller
{
    public function __invoke(Request $request, OwnProtocols $lists): Response
    {
        $source = $request->query('source');
        $data = $lists->for($request->user(), is_string($source) ? $source : null);

        return Inertia::render('Me/Protocols', $data + ['source' => $data['groups'] === [] || ! is_string($source) ? '' : $source]);
    }
}
