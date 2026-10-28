<?php

namespace App\Jobs;

use App\Discord\DiscordException;
use App\Discord\DiscordWebhook;
use App\Discord\WebhookKind;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One webhook message (legacy SendDiscordWebhookJob). The payload is the kind and the embed (event data, no secret): the
 * URL is read from the settings when the job runs, so `failed_jobs` never holds it. Network trouble and outages are
 * retried, a rate limit waits as long as Discord asks, a refusal (4xx: wrong or deleted URL) is logged without retry.
 */
class SendDiscordWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 60, 120];

    /** @param array<string, mixed> $embed */
    public function __construct(public WebhookKind $kind, public array $embed)
    {
        $this->onQueue('default');
        $this->afterCommit();
    }

    public function handle(DiscordWebhook $webhook): void
    {
        try {
            $webhook->send($this->kind, $this->embed);
        } catch (DiscordException $e) {
            $context = ['kind' => $this->kind->value, 'status' => $e->status, 'code' => $e->getCode()];
            if ($e->status === 429) {
                Log::info('Discord webhook rate limited', $context);
                $this->release($e->retryAfter ?? $this->backoff[min($this->attempts() - 1, 2)]);

                return;
            }
            if ($e->retryable()) {
                throw $e;
            }
            Log::info('Discord webhook not delivered', $context);
        }
    }
}
