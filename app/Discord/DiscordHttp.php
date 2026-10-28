<?php

namespace App\Discord;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * What every outgoing Discord call shares (bot and webhooks): no redirects, short timeouts, a streamed body of which at
 * most CAP bytes are read (a hostile or broken endpoint cannot make us buffer a large answer), and the mapping of an
 * error answer to a DiscordException written for people.
 */
final class DiscordHttp
{
    public const USER_AGENT = 'DiscordBot (https://github.com/EmergencyForge/ignis, 1)';

    public const CAP = 16384;

    public static function pending(): PendingRequest
    {
        return Http::withUserAgent(self::USER_AGENT)->acceptJson()->withoutRedirecting()
            ->withOptions(['stream' => true])->timeout(10)->connectTimeout(5);
    }

    /** @return array<array-key, mixed> the JSON object of the answer; empty when it is none (or too large to be one) */
    public static function json(Response $response): array
    {
        $stream = $response->toPsrResponse()->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $raw = '';
        while (! $stream->eof() && strlen($raw) < self::CAP) {
            $raw .= $stream->read(self::CAP - strlen($raw));
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /** @param array<array-key, mixed> $data */
    public static function failure(Response $response, array $data): DiscordException
    {
        $status = $response->status();
        $code = is_int($data['code'] ?? null) ? $data['code'] : 0;
        $retryAfter = null;
        if ($status === 429) {
            $wait = $data['retry_after'] ?? $response->header('Retry-After');
            $retryAfter = is_numeric($wait) ? max(1, (int) ceil((float) $wait)) : null;
        }

        $message = match (true) {
            $status === 401 => __('discord.error.unauthorized'),
            $code === DiscordException::CANNOT_DM => __('discord.error.cannot_dm'),
            $status === 429 => __('discord.error.rate_limited'),
            $status >= 500 => __('discord.error.outage', ['status' => $status]),
            default => __('discord.error.other', ['reason' => self::reason($data), 'status' => $status]),
        };

        return new DiscordException($message, $status, $code, $retryAfter);
    }

    /**
     * Discord's own words: field errors sit nested in `errors`, else `message`. Cut short; Discord never echoes the token.
     *
     * @param  array<array-key, mixed>  $data
     */
    private static function reason(array $data): string
    {
        $details = [];
        $errors = is_array($data['errors'] ?? null) ? $data['errors'] : [];
        array_walk_recursive($errors, function (mixed $value, string|int $key) use (&$details) {
            if ($key === 'message' && is_string($value)) {
                $details[] = $value;
            }
        });
        $reason = $details !== [] ? implode(' ', array_unique($details)) : (is_string($data['message'] ?? null) ? $data['message'] : '');

        return mb_substr($reason !== '' ? $reason : __('discord.error.unknown'), 0, 200);
    }
}
