<?php

declare(strict_types=1);

namespace App\Runtime;

final readonly class ContainerManifest
{
    /**
     * @param  list<string>  $tmpfs
     * @param  list<string>  $mounts
     * @param  array<string, string>  $environment
     */
    public function __construct(
        public string $image,
        public string $networkMode,
        public float $cpus,
        public string $memory,
        public int $pidsLimit,
        public bool $readOnlyRoot,
        public array $tmpfs,
        public string $user,
        public array $mounts = [],
        public array $environment = [],
        public int $timeoutSeconds = 300,
    ) {}
}
