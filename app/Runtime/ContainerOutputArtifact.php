<?php

declare(strict_types=1);

namespace App\Runtime;

final readonly class ContainerOutputArtifact
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $kind,
        public string $purpose,
        public string $content,
        public string $contentType,
        public string $filename,
        public array $metadata = [],
    ) {}

    public function withContent(string $content): self
    {
        return new self(
            kind: $this->kind,
            purpose: $this->purpose,
            content: $content,
            contentType: $this->contentType,
            filename: $this->filename,
            metadata: $this->metadata,
        );
    }
}
