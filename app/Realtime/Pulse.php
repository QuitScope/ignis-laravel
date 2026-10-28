<?php

namespace App\Realtime;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * "Something changed" on a private channel: only a fixed topic word and an id, never names, numbers, status codes, texts
 * or patient data. The page reloads through its own policy-checked endpoints. Sent via Realtime::pulse only.
 */
final class Pulse implements ShouldBroadcastNow
{
    public function __construct(public readonly string $channel, public readonly string $topic, public readonly ?int $id = null) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channel);
    }

    public function broadcastAs(): string
    {
        return 'pulse';
    }

    /** @return array{topic: string, id?: int} */
    public function broadcastWith(): array
    {
        return $this->id === null ? ['topic' => $this->topic] : ['topic' => $this->topic, 'id' => $this->id];
    }
}
