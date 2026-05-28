<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Events\RackLabBroadcastEvent;
use App\Models\BroadcastEventLog;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

final readonly class BroadcastEventLogWriter
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function append(string $tenantId, string $channel, string $eventClass, array $payload): BroadcastEventLog
    {
        /** @var BroadcastEventLog $event */
        $event = BroadcastEventLog::query()->create([
            'id' => Str::ulid()->toString(),
            'tenant_id' => $tenantId,
            'channel' => $channel,
            'event_class' => $eventClass,
            'payload' => $payload,
            'created_at' => now(),
        ]);

        Event::dispatch(new RackLabBroadcastEvent(
            eventId: $event->id,
            channel: $channel,
            eventClass: $eventClass,
            payload: $payload,
        ));

        return $event;
    }
}
