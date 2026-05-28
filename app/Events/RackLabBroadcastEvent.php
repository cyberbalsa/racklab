<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class RackLabBroadcastEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventId,
        public string $channel,
        public string $eventClass,
        public array $payload,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        if (str_starts_with($this->channel, 'private-')) {
            return [new PrivateChannel(substr($this->channel, strlen('private-')))];
        }

        return [new Channel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return $this->eventClass;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->eventId,
            'event_class' => $this->eventClass,
            'payload' => $this->payload,
        ];
    }
}
