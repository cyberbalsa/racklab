<?php

declare(strict_types=1);

namespace App\Docs\Refs\Resolving;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use InvalidArgumentException;

/**
 * Default sampler. Bounds `docs.ref_resolve` audit volume two ways:
 *
 *  1. Successful resolutions are thinned by a probability sample
 *     (`docs.ref_resolve_audit_sample_rate`, 0.0–1.0). The extremes are
 *     deterministic, which keeps tests stable.
 *  2. *Every* outcome (including redacted / not-found / unsupported) is
 *     de-duplicated per `(actor, kind, id, status)` within a rolling
 *     window (`docs.ref_resolve_audit_dedup_window_seconds`) using an
 *     atomic `Cache::add`. A page that polls a redacted or missing ref on
 *     an interval therefore records at most one audit row per window
 *     instead of one per poll.
 *
 * The probability gate runs first for successful resolutions, so a
 * sampled-out success neither records nor reserves a dedup slot.
 */
final readonly class CacheDedupRefResolveAuditSampler implements RefResolveAuditSampler
{
    public function __construct(
        private CacheRepository $cache,
        private float $resolvedSampleRate,
        private int $dedupWindowSeconds,
    ) {
        if ($resolvedSampleRate < 0.0 || $resolvedSampleRate > 1.0) {
            throw new InvalidArgumentException('Resolved sample rate must be between 0.0 and 1.0.');
        }

        if ($dedupWindowSeconds < 0) {
            throw new InvalidArgumentException('Dedup window seconds must not be negative.');
        }
    }

    public function shouldRecord(
        string $actorId,
        string $kind,
        string $id,
        RefResolutionStatus $status,
    ): bool {
        if ($status === RefResolutionStatus::Resolved && ! $this->passesProbability()) {
            return false;
        }

        if ($this->dedupWindowSeconds === 0) {
            return true;
        }

        $key = sprintf('docs:ref_resolve_audit:%s:%s:%s:%s', $actorId, $kind, $id, $status->value);

        // Cache::add is atomic: true only when the key was absent, i.e. the
        // first time this (actor, ref, outcome) is seen in the window.
        return $this->cache->add($key, true, $this->dedupWindowSeconds);
    }

    private function passesProbability(): bool
    {
        if ($this->resolvedSampleRate <= 0.0) {
            return false;
        }

        if ($this->resolvedSampleRate >= 1.0) {
            return true;
        }

        return random_int(1, 1_000_000) <= (int) round($this->resolvedSampleRate * 1_000_000);
    }
}
