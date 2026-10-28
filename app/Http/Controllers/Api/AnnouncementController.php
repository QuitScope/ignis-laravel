<?php

namespace App\Http\Controllers\Api;

use App\Announcements\AnnouncementFeed;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** POST /api/announcements/dismiss (also the legacy /api/dismiss-announcement): hides a hub announcement for the signed-in account. */
class AnnouncementController extends Controller
{
    public function dismiss(Request $request, AnnouncementFeed $feed): JsonResponse
    {
        $id = $request->validate(['announcement_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/D']])['announcement_id'];

        // Only ids the cache knows are stored, so the table cannot be filled with arbitrary strings; repeating is fine.
        if ($feed->knows($id)) {
            DB::table('announcement_dismissals')->insertOrIgnore(['announcement_id' => $id, 'user_id' => $request->user()->id, 'dismissed_at' => now()]);
        }

        return response()->json(['success' => true]);
    }
}
