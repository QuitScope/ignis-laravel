<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileDiscordDmRequest;
use App\Http\Requests\ProfileThemeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Settings of the signed-in account itself (legacy ProfileController); the row is always the session's, never a request id. */
class ProfileController extends Controller
{
    public function theme(ProfileThemeRequest $request): RedirectResponse|Response
    {
        $request->user()->update(['theme' => $request->validated('theme')]);

        return $this->done($request);
    }

    public function discordDm(ProfileDiscordDmRequest $request): RedirectResponse|Response
    {
        $request->user()->update(['discord_dm' => $request->boolean('discord_dm')]);

        return $this->done($request);
    }

    private function done(Request $request): RedirectResponse|Response
    {
        return $request->wantsJson() ? response()->noContent() : back();
    }
}
