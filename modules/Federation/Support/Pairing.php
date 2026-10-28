<?php

namespace Modules\Federation\Support;

use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Modules\Federation\Models\FederationLink;

/**
 * Pairing v2. A invites: a one-time code (stored as SHA-256, 15 min, one open invite) travels inside the
 * token. B accepts: POSTs the code and its fresh secret to A's /api/federation/pair, stores the link and confirms it
 * with a signed handshake. Codes, tokens and secrets never reach a log, the audit or an error text.
 */
final class Pairing
{
    public const SETTING = 'federation.invite';

    public const TTL_MINUTES = 15;

    public static function invite(): string
    {
        $code = Str::random(32);
        app(Settings::class)->set(self::SETTING, ['hash' => hash('sha256', $code), 'expires' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp()]);

        $json = (string) json_encode(['v' => 2, 'url' => rtrim((string) config('app.url'), '/'), 'instance_id' => FederationInstance::instanceId(), 'name' => self::name(), 'code' => $code], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * Side A: true when $code is the open, unexpired invite; it is consumed right here (before any work), atomically:
     * the row is deleted only if it still holds the value read, so a second submission of the same code loses.
     */
    public static function consume(#[\SensitiveParameter] string $code): bool
    {
        $row = Setting::query()->where('key', self::SETTING)->first();
        $invite = $row === null ? null : json_decode((string) $row->value, true);
        if (! is_array($invite) || ! is_string($invite['hash'] ?? null) || ! is_int($invite['expires'] ?? null)) {
            return false;
        }
        if (! hash_equals($invite['hash'], hash('sha256', $code))) {
            return false;
        }
        $gone = Setting::query()->where('key', self::SETTING)->where('value', $row->value)->delete() === 1;
        app(Settings::class)->refresh();

        return $gone && $invite['expires'] >= now()->getTimestamp();
    }

    public static function accept(#[\SensitiveParameter] string $token): FederationLink|string
    {
        $t = json_decode((string) base64_decode(strtr(trim($token), '-_', '+/'), true), true);
        if (! is_array($t) || ($t['v'] ?? null) !== 2 || ! is_string($t['url'] ?? null) || ! is_string($t['code'] ?? null)
            || ! is_string($t['instance_id'] ?? null) || ! Str::isUuid($t['instance_id']) || ! is_string($t['name'] ?? null)) {
            return 'invalid_token';
        }
        if ($t['instance_id'] === FederationInstance::instanceId()) {
            return 'self';
        }
        if (FederationLink::query()->where('instance_id', $t['instance_id'])->whereNotNull('confirmed_at')->exists()) {
            return 'already_linked';
        }
        $url = rtrim($t['url'], '/');
        if (($code = PeerHttp::check($url)) !== null) {
            return $code;
        }

        $secretForThem = Str::random(64);
        $res = PeerHttp::send($url, 'POST', '/api/federation/pair', json: [
            'v' => 2, 'instance_id' => FederationInstance::instanceId(), 'name' => self::name(),
            'url' => rtrim((string) config('app.url'), '/'), 'code' => $t['code'], 'secret_for_you' => $secretForThem,
        ]);
        if (is_string($res)) {
            return $res;
        }
        $secretForUs = $res->json('secret_for_you');
        if ($res->json('instance_id') !== $t['instance_id'] || ! is_string($secretForUs) || strlen($secretForUs) < 32) {
            return 'bad_json';
        }

        $link = FederationLink::query()->where('instance_id', $t['instance_id'])->first() ?? new FederationLink;
        $link->forceFill(['instance_id' => $t['instance_id']] + self::fresh(mb_substr(trim((string) ($res->json('name') ?: $t['name'])), 0, 100), $url, $secretForUs, $secretForThem))->save();

        $hello = PeerHttp::send($link, 'GET', '/api/federation/handshake', sign: $link);
        if ($hello instanceof Response) {
            $link->forceFill(['confirmed_at' => now()])->save();
        }

        return $link;
    }

    /**
     * Attributes of a new (or replaced half) link: every flag off, no cursors.
     *
     * @return array<string, mixed>
     */
    public static function fresh(string $name, string $url, #[\SensitiveParameter] string $outgoing, #[\SensitiveParameter] string $incoming): array
    {
        return [
            'name' => $name, 'url' => $url, 'secret_outgoing' => $outgoing, 'secret_incoming' => $incoming,
            'consume_personnel' => false, 'consume_enotf' => false, 'consume_fire' => false,
            'provide_personnel' => false, 'provide_enotf' => false, 'provide_fire' => false,
            'is_active' => true, 'confirmed_at' => null, 'cursors' => null,
            'last_sync_at' => null, 'last_sync_status' => 'pending', 'last_sync_error' => null,
        ];
    }

    public static function name(): string
    {
        $name = app(Settings::class)->get('federation.instance_name');

        return is_string($name) && $name !== '' ? $name : (string) config('app.name');
    }
}
