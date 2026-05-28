<?php

declare(strict_types=1);

namespace App\Runtime;

use App\Contracts\ContainerRuntime;
use RuntimeException;

final readonly class UnavailableContainerRuntime implements ContainerRuntime
{
    public function run(ContainerRunRequest $request): ContainerRunResult
    {
        throw new RuntimeException('No RackLab container runtime is configured.');
    }
}
