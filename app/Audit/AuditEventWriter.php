<?php

declare(strict_types=1);

namespace App\Audit;

use App\Domain\Audit\AuditHash;
use App\Models\AuditEvent;
use Illuminate\Support\Facades\DB;

final readonly class AuditEventWriter
{
    public function __construct(private AuditHash $auditHash) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function append(array $attributes): AuditEvent
    {
        return DB::transaction(function () use ($attributes): AuditEvent {
            /** @var AuditEvent|null $previous */
            $previous = AuditEvent::query()
                ->lockForUpdate()
                ->latest('id')
                ->first();

            $event = new AuditEvent($this->normalize($attributes));
            $event->prev_hash = $previous?->hash;
            $event->hash = $this->auditHash->calculate($event->prev_hash, $event->hashPayload());
            $event->save();

            return $event;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalize(array $attributes): array
    {
        return [
            ...$attributes,
            'effective_permissions' => $attributes['effective_permissions'] ?? [],
            'metadata' => $attributes['metadata'] ?? [],
            'occurred_at' => $attributes['occurred_at'] ?? now(),
            'target_tenant_set' => $attributes['target_tenant_set'] ?? [],
        ];
    }
}
