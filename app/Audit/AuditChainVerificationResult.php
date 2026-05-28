<?php

declare(strict_types=1);

namespace App\Audit;

final readonly class AuditChainVerificationResult
{
    private function __construct(
        public bool $valid,
        public ?int $eventId,
        public ?string $reason,
    ) {}

    public static function valid(): self
    {
        return new self(valid: true, eventId: null, reason: null);
    }

    public static function invalid(int $eventId, string $reason): self
    {
        return new self(valid: false, eventId: $eventId, reason: $reason);
    }
}
