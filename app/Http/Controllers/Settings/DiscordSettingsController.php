<?php

namespace App\Http\Controllers\Settings;

use App\Discord\DiscordBot;
use App\Discord\DiscordException;
use App\Discord\DmRecipients;
use App\Discord\WebhookKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\DiscordSettingsRequest;
use App\Notifications\NotificationKinds;
use App\Support\AuditLogger;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Einstellungen › System › Discord-Bot (legacy DiscordBotController): token, switch, which notification kinds go out by
 * DM, a test message to the own account. A new token is checked with Discord first (GET /users/@me) and stored only when
 * Discord accepts it; id, name and avatar are what Discord reports. The token is write-only: the page learns whether one
 * is set, never the value, and it is never audited, logged or flashed. Name and avatar are not changed from here.
 */
class DiscordSettingsController extends Controller
{
    private const PROFILE = ['discord.bot.id' => 'id', 'discord.bot.name' => 'name', 'discord.bot.avatar' => 'avatar'];

    public function index(Request $request, DiscordBot $bot, Settings $settings, NotificationKinds $kinds, DmRecipients $recipients): Response
    {
        $this->authorize('system.admin');
        $labels = $kinds->labels();
        asort($labels, SORT_NATURAL | SORT_FLAG_CASE);

        return Inertia::render('Settings/System/Discord', [
            'bot' => [
                'enabled' => $settings->get('discord.bot.enabled') === true,
                'connected' => $bot->token() !== null,
                'tokenLost' => $bot->tokenLost(),
                'id' => (string) $settings->get('discord.bot.id'),
                'name' => (string) $settings->get('discord.bot.name'),
                'avatarUrl' => $bot->avatarUrl(),
                'inviteUrl' => $bot->inviteUrl(),
                'dmKinds' => array_values((array) $settings->get('discord.dm_kinds')),
            ],
            'kinds' => array_map(fn (string $key, string $label) => ['key' => $key, 'label' => $label], array_keys($labels), $labels),
            'reach' => $recipients->reach(),
            // Webhook URLs carry a token: the page only learns whether one is stored.
            'webhooks' => array_map(fn (WebhookKind $k) => ['kind' => $k->value, 'label' => $k->label(), 'set' => $settings->has($k->settingKey())], WebhookKind::cases()),
            'ownDiscordId' => DmRecipients::discordId($request->user()) !== null,
        ]);
    }

    public function update(DiscordSettingsRequest $request, DiscordBot $bot, Settings $settings, NotificationKinds $kindRegistry): RedirectResponse
    {
        $token = (string) $request->validated('token');
        $enabled = $request->boolean('enabled');
        $kinds = array_values($request->validated('dm_kinds'));

        $profile = null;
        if ($token !== '') {
            try {
                $profile = $bot->me($token);
            } catch (DiscordException $e) {
                throw ValidationException::withMessages(['token' => $e->getMessage()]);
            }
        }
        if ($enabled && $profile === null && $bot->token() === null) {
            throw ValidationException::withMessages(['enabled' => __('settings.discord.error.no_token')]);
        }

        $stored = array_values((array) $settings->get('discord.dm_kinds'));
        $kinds = array_values(array_unique([...$kinds, ...array_diff($stored, array_keys($kindRegistry->labels()))]));
        sort($stored);
        $sorted = $kinds;
        sort($sorted);

        DB::transaction(function () use ($request, $bot, $settings, $token, $profile, $enabled, $kinds, $stored, $sorted) {
            $changed = [];
            if ($profile !== null) {
                if ($token !== $bot->token()) {
                    $changed[] = 'Token';
                }
                $settings->set('discord.bot.token', $token, encrypted: true);
                foreach (self::PROFILE as $key => $field) {
                    $settings->set($key, $profile[$field]);
                }
            }
            if ($enabled !== ($settings->get('discord.bot.enabled') === true)) {
                $settings->set('discord.bot.enabled', $enabled);
                $changed[] = $enabled ? 'eingeschaltet' : 'ausgeschaltet';
            }
            if ($sorted !== $stored) {
                $settings->set('discord.dm_kinds', $kinds);
                $changed[] = 'Direktnachrichten';
            }
            if ($changed !== []) {
                AuditLogger::log($request->user(), 'Discord-Bot geändert', implode(', ', $changed), 'System', true);
            }
        });

        return redirect('/settings/system/discord')->with('status', 'settings.discord.saved');
    }

    public function destroy(Request $request, Settings $settings): RedirectResponse
    {
        $this->authorize('system.admin');
        $connected = $settings->has('discord.bot.token') || $settings->get('discord.bot.enabled') === true;
        foreach (['discord.bot.token', ...array_keys(self::PROFILE)] as $key) {
            $settings->forget($key);
        }
        $settings->set('discord.bot.enabled', false);
        if ($connected) {
            AuditLogger::log($request->user(), 'Discord-Bot getrennt', null, 'System', true);
        }

        return redirect('/settings/system/discord')->with('status', 'settings.discord.disconnected');
    }

    public function test(Request $request, DiscordBot $bot): RedirectResponse
    {
        $this->authorize('system.admin');
        $discordId = DmRecipients::discordId($request->user());
        if ($bot->token() === null || $discordId === null) {
            throw ValidationException::withMessages(['test' => $bot->token() === null ? __('discord.error.not_set_up') : __('settings.discord.test.no_id')]);
        }

        try {
            $bot->sendDm($discordId, $bot->embed(__('settings.discord.test.message_title'), __('settings.discord.test.message_text'), '/settings/system/discord', __('notifications.kind.system')));
        } catch (DiscordException $e) {
            throw ValidationException::withMessages(['test' => $e->getMessage()]);
        }

        return redirect('/settings/system/discord')->with('status', 'settings.discord.test_sent');
    }
}
