<?php

declare(strict_types=1);

namespace App\Docs\Refs\Resolving;

use InvalidArgumentException;

/**
 * Default sampler: always record security-relevant outcomes (redacted /
 * not-found / unsupported) and sample successful resolutions at a
 * configurable rate (`docs.ref_resolve_audit_sample_rate`, 0.0–1.0).
 *
 * A rate of 0.0 records no successful resolutions; 1.0 records all. The
 * extremes are deterministic, which keeps tests stable.
 */
final readonly class ProbabilityRefResolveAuditSampler implements RefResolveAuditSampler
{
    public function __construct(private float $resolvedSampleRate)
    {
        if ($resolvedSampleRate < 0.0 || $resolvedSampleRate > 1.0) {
            throw new InvalidArgumentException('Resolved sample rate must be between 0.0 and 1.0.');
        }
    }

    public function shouldRecord(RefResolutionStatus $status): bool
    {
        if ($status !== RefResolutionStatus::Resolved) {
            return true;
        }

        if ($this->resolvedSampleRate <= 0.0) {
            return false;
        }

        if ($this->resolvedSampleRate >= 1.0) {
            return true;
        }

        return random_int(1, 1_000_000) <= (int) round($this->resolvedSampleRate * 1_000_000);
    }
}
