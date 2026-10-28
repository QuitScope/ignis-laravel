<?php

namespace App\Http\Controllers;

use App\Discord\DiscordBot;
use App\Discord\DiscordException;
use App\Models\Personnel;
use App\Models\RegistrationCode;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /personnel/{personnel}/invite: the invitation link for a record without account (legacy
 * Api\PersonnelController::generateInvite). An open code of the record is handed out again instead of piling up new
 * ones. With `via=discord` the bot also sends the link as a DM to the record's Discord id, synchronously; `discord`
 * in the answer says whether that worked. Without an active bot or a Discord id no code is made at all.
 */
class PersonnelInvitationController extends Controller
{
    public function __invoke(Request $request, Personnel $personnel, DiscordBot $bot): JsonResponse
    {
        $this->authorize('update', $personnel);
        $this->authorize('createRegistrationCode', User::class);
        $request->validate(['via' => ['nullable', 'in:discord']]);
        $viaDiscord = $request->input('via') === 'discord';
        if ($viaDiscord && ! $bot->active()) {
            return response()->json(['message' => __('account_link.discord_inactive')], 422);
        }
        if ($viaDiscord && ! DiscordBot::snowflake($personnel->discord_id)) {
            return response()->json(['message' => __('account_link.discord_no_id')], 422);
        }

        $result = DB::transaction(function () use ($request, $personnel) {
            Personnel::query()->whereKey($personnel->id)->lockForUpdate()->value('id');
            if (User::query()->where('personnel_id', $personnel->id)->exists()) {
                return null;
            }

            $cutoff = now()->addDay()->toDateTimeString();
            $open = RegistrationCode::query()->where('personnel_id', $personnel->id)->whereNull('used_at')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $cutoff))
                ->latest('id')->first();
            if ($open !== null) {
                return [$open, true];
            }

            $code = RegistrationCode::generate($request->user(), $personnel->full_name, null);
            $code->personnel_id = $personnel->id;
            $code->save();

            return [$code, false];
        });

        if ($result === null) {
            return response()->json(['message' => __('account_link.personnel_taken')], 422);
        }

        [$code, $existing] = $result;
        if (! $existing) {
            AuditLogger::log($request->user(), 'Einladung erstellt', $code->label, 'Einladungen', true, ['registration_code_id' => $code->id, 'personnel_id' => $personnel->id]);
        }

        $answer = ['url' => $code->url(), 'existing' => $existing];
        if ($viaDiscord) {
            try {
                $this->sendInvite($bot, $personnel, $code);
                AuditLogger::log($request->user(), 'Einladung per Discord gesendet', $code->label, 'Einladungen', true, ['registration_code_id' => $code->id, 'personnel_id' => $personnel->id]);
                $answer['discord'] = ['sent' => true];
            } catch (DiscordException $e) {
                $answer['discord'] = ['sent' => false, 'message' => $e->getMessage()];
            }
        }

        return response()->json($answer)->header('Cache-Control', 'no-store');
    }

    private function sendInvite(DiscordBot $bot, Personnel $personnel, RegistrationCode $code): void
    {
        $system = (string) (app(Settings::class)->get('system.name') ?: config('app.name'));
        $name = trim((string) $personnel->full_name);
        $text = $name !== '' ? __('account_link.discord_text', ['name' => $name]) : __('account_link.discord_text_anonymous');
        $embed = $bot->embed(__('account_link.discord_title', ['system' => $system]), $text, '/invite?code='.$code->code, __('account_link.discord_label'));
        $button = ['type' => 2, 'style' => 5, 'label' => __('account_link.discord_button'), 'url' => $code->url()];

        $bot->sendDm((string) $personnel->discord_id, $embed, [['type' => 1, 'components' => [$button]]]);
    }
}
