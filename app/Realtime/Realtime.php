<?php

namespace App\Realtime;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one way to send a Pulse: nothing without BROADCAST_CONNECTION=reverb; after the commit (a rollback
 * sends nothing); after the response (defer), so a dead Reverb never delays a request; one pulse per channel/topic/id
 * and request (the defer name), so an EMD sync touching many rows sends each once. Errors stay silent, a warning at most
 * every 5 minutes; the pages' fallback poll catches what is lost (pulses are at-most-once anyway).
 */
final class Realtime
{
    public static function pulse(string $channel, string $topic, ?int $id = null): void
    {
        if (config('broadcasting.default') !== 'reverb') {
            return;
        }

        DB::afterCommit(fn () => defer(fn () => self::send(new Pulse($channel, $topic, $id)), "pulse:$channel:$topic:$id", always: true));
    }

    private static function send(Pulse $pulse): void
    {
        if (rescue(fn () => Cache::has('realtime.down'), false, report: false)) {
            return;
        }

        try {
            event($pulse);
        } catch (Throwable $e) {
            rescue(fn () => Cache::put('realtime.down', 1, 30), report: false);
            // No channel name or payload in the log (the channel may carry an account id).
            rescue(fn () => Cache::add('realtime.warned', 1, 300) && Log::warning('Realtime pulse not delivered: '.$e::class), report: false);
        }
    }
}
