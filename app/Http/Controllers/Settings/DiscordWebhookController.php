<?php

namespace App\Http\Controllers\Settings;

use App\Discord\DiscordException;
use App\Discord\DiscordWebhook;
use App\Discord\Embeds;
use App\Discord\WebhookKind;
use App\Discord\WebhookUrlRule;
use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The three webhook URLs on the Discord page. The URL is write-only like the bot token: the page learns
 * set or not, never the value; the audit says changed or removed, never the URL; a refused value is not flashed back
 * (`webhook_url` is in dontFlash) and its message does not repeat it. An empty field changes nothing.
 */
class DiscordWebhookController extends Controller
{
    public function update(Request $request, WebhookKind $kind, Settings $settings): RedirectResponse
    {
        $this->authorize('system.admin');
        $request->validate(['webhook_url' => ['nullable', 'string']], [], ['webhook_url' => $kind->label()]);
        $url = trim((string) $request->input('webhook_url'));
        if ($url !== '') {
            $request->validate(['webhook_url' => ['max:300', WebhookUrlRule::make()]], [], ['webhook_url' => $kind->label()]);
            $settings->set($kind->settingKey(), $url, encrypted: true);
            AuditLogger::log($request->user(), 'Discord-Webhook geändert', $kind->value.': geändert', 'System', true);
        }

        return $this->back('settings.discord.webhooks.saved');
    }

    public function destroy(Request $request, WebhookKind $kind, Settings $settings): RedirectResponse
    {
        $this->authorize('system.admin');
        if ($settings->has($kind->settingKey())) {
            $settings->forget($kind->settingKey());
            AuditLogger::log($request->user(), 'Discord-Webhook geändert', $kind->value.': entfernt', 'System', true);
        }

        return $this->back('settings.discord.webhooks.removed');
    }

    public function test(Request $request, WebhookKind $kind, DiscordWebhook $webhook): RedirectResponse
    {
        $this->authorize('system.admin');
        try {
            $sent = $webhook->send($kind, Embeds::sample($kind));
        } catch (DiscordException $e) {
            throw ValidationException::withMessages(['webhook_test' => $kind->label().': '.$e->getMessage()]);
        }
        if (! $sent) {
            throw ValidationException::withMessages(['webhook_test' => $kind->label().': '.__('settings.discord.webhooks.test_unset')]);
        }

        return $this->back('settings.discord.webhooks.test_sent');
    }

    private function back(string $status): RedirectResponse
    {
        return redirect('/settings/system/discord')->with('status', $status);
    }
}
