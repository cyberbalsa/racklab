<?php

declare(strict_types=1);

namespace App\Scheduling;

final readonly class PlacementDecision
{
    /**
     * @param  list<string>  $candidateNodes
     * @param  list<string>  $reasons
     */
    public function __construct(
        public string $provider,
        public string $node,
        public array $candidateNodes,
        public array $reasons,
    ) {}
}
