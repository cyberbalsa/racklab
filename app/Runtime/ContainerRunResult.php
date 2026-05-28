<?php

declare(strict_types=1);

namespace App\Runtime;

final readonly class ContainerRunResult
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<ContainerOutputArtifact>  $artifacts
     */
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public array $metadata = [],
        public bool $timedOut = false,
        public array $artifacts = [],
    ) {}
}
