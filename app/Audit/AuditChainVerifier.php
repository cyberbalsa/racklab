<?php

declare(strict_types=1);

namespace App\Audit;

use App\Domain\Audit\AuditHash;
use App\Models\AuditEvent;

final readonly class AuditChainVerifier
{
    public function __construct(private AuditHash $auditHash) {}

    public function verify(): AuditChainVerificationResult
    {
        $previousHash = null;

        /** @var AuditEvent $event */
        foreach (AuditEvent::query()->orderBy('id')->cursor() as $event) {
            if ($event->prev_hash !== $previousHash) {
                return AuditChainVerificationResult::invalid(
                    eventId: $event->id,
                    reason: 'Previous hash pointer mismatch.',
                );
            }

            $expectedHash = $this->auditHash->calculate($previousHash, $event->hashPayload());

            if (! hash_equals($expectedHash, $event->hash)) {
                return AuditChainVerificationResult::invalid(
                    eventId: $event->id,
                    reason: 'Event hash mismatch.',
                );
            }

            $previousHash = $event->hash;
        }

        return AuditChainVerificationResult::valid();
    }
}
