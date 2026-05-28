<?php

declare(strict_types=1);

namespace App\Docs\Refs\Resolving;

/**
 * Decides whether a single cross-link resolution should be audited.
 *
 * Resolver pills poll on an interval, so every outcome — including the
 * security-relevant redacted / not-found / unsupported ones — recurs on
 * each poll. Recording every poll would let a single bad ref or denied
 * reader append unbounded audit rows. Implementations therefore bound
 * volume for *all* outcomes (e.g. record once per actor/ref/outcome
 * window) and additionally thin the high-volume successful resolutions.
 */
interface RefResolveAuditSampler
{
    public function shouldRecord(
        string $actorId,
        string $kind,
        string $id,
        RefResolutionStatus $status,
    ): bool;
}
