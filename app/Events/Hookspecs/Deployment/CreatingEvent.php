<?php

declare(strict_types=1);

namespace App\Events\Hookspecs\Deployment;

final readonly class CreatingEvent
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $tenantId,
        public string $projectId,
        public string $stackDefinitionId,
        public array $metadata,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            tenantId: $this->tenantId,
            projectId: $this->projectId,
            stackDefinitionId: $this->stackDefinitionId,
            metadata: [
                ...$this->metadata,
                ...$metadata,
            ],
        );
    }
}
