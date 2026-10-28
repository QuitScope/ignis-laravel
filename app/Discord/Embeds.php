<?php

namespace App\Discord;

use Carbon\Carbon;
use Throwable;

/**
 * The three webhook embeds as pure functions of the event data (legacy DiscordWebhook::notify*). Discord rejects a whole
 * message for an empty or over-long field or an invalid link, so every value goes through field() ("Unbekannt" when
 * empty, at most 1024 characters) and links exist only from config('app.url'). The send time is added by DiscordWebhook.
 */
final class Embeds
{
    public const FIELD_LIMIT = 1024;

    public const TOTAL_LIMIT = 6000;

    public const COLOR_ENOTF = 3447003;

    public const COLOR_FIRE = 15158332;

    /**
     * @param  array<string, mixed>  $data  enr, last_edit
     * @return array<string, mixed>
     */
    public static function enotfProtocol(array $data): array
    {
        $enr = self::text($data['enr'] ?? null);

        return self::compact([
            'title' => '📋 '.__('discord.webhook.enotf_protocol.title'),
            'description' => __('discord.webhook.enotf_protocol.text'),
            'color' => self::COLOR_ENOTF,
            'fields' => [
                self::field(__('discord.webhook.enr'), $enr !== '' ? "**#{$enr}**" : null),
                self::field(__('discord.webhook.timestamp'), $data['last_edit'] ?? now()->format('Y-m-d H:i:s')),
            ],
            'url' => $enr !== '' ? self::link('/enotf/p/'.rawurlencode($enr)) : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data  id, incident_number, location, keyword, started_at, leader_name
     * @return array<string, mixed>
     */
    public static function fireProtocol(array $data): array
    {
        $id = (int) (is_scalar($data['id'] ?? null) ? $data['id'] : 0);
        $number = self::text($data['incident_number'] ?? null);

        return self::compact([
            'title' => '🚒 '.__('discord.webhook.fire_protocol.title'),
            'description' => __('discord.webhook.fire_protocol.text'),
            'color' => self::COLOR_FIRE,
            'fields' => [
                self::field(__('discord.webhook.enr'), $number !== '' ? "**{$number}**" : null),
                self::field(__('discord.webhook.location'), $data['location'] ?? null),
                self::field(__('discord.webhook.keyword'), $data['keyword'] ?? null),
                self::field(__('discord.webhook.started_at'), $data['started_at'] ?? null),
                self::field(__('discord.webhook.leader'), $data['leader_name'] ?? null),
            ],
            'url' => $id > 0 ? self::link('/firetab/qm/incidents/'.$id) : null,
        ]);
    }

    /**
     * Pre-registration: urgency, arrival, vehicle and hospital only, never a diagnosis or other patient data
     * (legacy sent diagnosis, intubation and circulation). The link leads to the hospital's arrival board (clinic device).
     *
     * @param  array<string, mixed>  $data  priority (0 to 2), arrival, vehicle, hospital
     * @return array<string, mixed>
     */
    public static function prereg(array $data): array
    {
        $raw = self::text($data['priority'] ?? null);
        [$priority, $emoji, $color] = match ($raw) {
            '2' => [__('discord.webhook.priority.2'), '🔴', 15158332],
            '1' => [__('discord.webhook.priority.1'), '🟡', 16776960],
            '0' => [__('discord.webhook.priority.0'), '🟢', 5763719],
            default => [$raw !== '' ? $raw : __('discord.webhook.unknown'), '⚪', 10181046],
        };

        return self::compact([
            'title' => '🏥 '.__('discord.webhook.prereg.title'),
            'description' => __('discord.webhook.prereg.text'),
            'color' => $color,
            'fields' => [
                self::field(__('discord.webhook.priority'), "{$emoji} **{$priority}**"),
                self::field(__('discord.webhook.arrival'), self::date($data['arrival'] ?? null)),
                self::field(__('discord.webhook.vehicle'), $data['vehicle'] ?? null),
                self::field(__('discord.webhook.hospital'), $data['hospital'] ?? null),
            ],
            'url' => self::link('/enotf/schnittstelle'),
        ]);
    }

    /**
     * The embed of the test button: made-up data, so the channel sees how a real message looks.
     *
     * @return array<string, mixed>
     */
    public static function sample(WebhookKind $kind): array
    {
        $example = __('discord.webhook.sample');

        return match ($kind) {
            WebhookKind::EnotfProtocol => self::enotfProtocol(['enr' => '2026-0001']),
            WebhookKind::FireProtocol => self::fireProtocol(['id' => 1, 'incident_number' => '2026-0001', 'location' => $example, 'keyword' => $example, 'started_at' => now()->format('d.m.Y H:i'), 'leader_name' => $example]),
            WebhookKind::EnotfPrereg => self::prereg(['priority' => 1, 'arrival' => now()->addMinutes(10)->toDateTimeString(), 'vehicle' => $example, 'hospital' => $example]),
        };
    }

    public static function link(string $path): ?string
    {
        $base = rtrim((string) config('app.url'), '/');

        return preg_match('#^https?://[^/\s]+$#i', $base) === 1 ? $base.$path : null;
    }

    /** @return array{name: string, value: string, inline: bool} */
    private static function field(string $name, mixed $value, bool $inline = true): array
    {
        $text = self::text($value);
        if ($text === '') {
            $text = __('discord.webhook.unknown');
        }
        if (mb_strlen($text) > self::FIELD_LIMIT) {
            $text = mb_substr($text, 0, self::FIELD_LIMIT - 1).'…';
        }

        return ['name' => $name, 'value' => $text, 'inline' => $inline];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function date(mixed $value): ?string
    {
        $text = self::text($value);
        try {
            return $text === '' ? null : Carbon::parse($text)->format('d.m.Y H:i');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $embed
     * @return array<string, mixed>
     */
    private static function compact(array $embed): array
    {
        return self::fit(array_filter($embed, fn (mixed $v) => $v !== null));
    }

    /**
     * Cuts the longest field value until the embed's countable text is within TOTAL_LIMIT (Discord refuses the whole
     * message otherwise). Names and the fixed texts stay; a value keeps at least one character.
     *
     * @param  array<string, mixed>  $embed
     * @return array<string, mixed>
     */
    public static function fit(array $embed): array
    {
        $fields = $embed['fields'] ?? [];
        $total = mb_strlen((string) ($embed['title'] ?? '')) + mb_strlen((string) ($embed['description'] ?? ''));
        foreach ($fields as $f) {
            $total += mb_strlen($f['name']) + mb_strlen($f['value']);
        }
        while ($total > self::TOTAL_LIMIT) {
            if ($fields === []) {
                break;
            }
            $len = max(array_map(fn ($g) => mb_strlen($g['value']), $fields));
            $i = array_key_last(array_filter($fields, fn ($f) => mb_strlen($f['value']) === $len));
            if ($i === null || $len <= 1) {
                break;
            }
            $cut = min($total - self::TOTAL_LIMIT, $len - 1);
            $fields[$i]['value'] = mb_substr($fields[$i]['value'], 0, $len - $cut - 1).'…';
            $total -= $cut;
        }
        if ($fields !== []) {
            $embed['fields'] = $fields;
        }

        return $embed;
    }
}
