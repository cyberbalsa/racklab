<?php

declare(strict_types=1);

namespace App\Quota;

use Carbon\CarbonImmutable;

final readonly class LeasePolicyDecision
{
    /**
     * @param  array<string, int>  $quotaRequirements
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ?CarbonImmutable $expiresAt,
        public ?int $durationMinutes,
        public array $quotaRequirements,
        public array $metadata,
    ) {}
}
