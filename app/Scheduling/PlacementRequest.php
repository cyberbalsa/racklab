<?php

declare(strict_types=1);

namespace App\Scheduling;

final readonly class PlacementRequest
{
    /**
     * @param  list<string>  $requiredTags
     * @param  list<string>  $antiAffinityExcludedNodes
     */
    public function __construct(
        public string $provider,
        public int $requiredVcpus,
        public int $requiredMemoryMb,
        public int $requiredStorageGb,
        public ?int $templateVmid = null,
        public ?string $providerCluster = null,
        public array $requiredTags = [],
        public array $antiAffinityExcludedNodes = [],
    ) {}
}
