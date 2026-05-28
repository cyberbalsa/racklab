<?php

declare(strict_types=1);

namespace App\Docs\Refs\Resolving;

/**
 * Decides whether a single cross-link resolution should be audited.
 *
 * Resolver pills poll on an interval, so successful resolutions are
 * high-volume and mostly noise; security-relevant outcomes (redacted,
 * not-found, unsupported) are comparatively rare and must not be lost.
 * Implementations therefore sample successful resolutions while always
 * recording the rest.
 */
interface RefResolveAuditSampler
{
    public function shouldRecord(RefResolutionStatus $status): bool;
}
