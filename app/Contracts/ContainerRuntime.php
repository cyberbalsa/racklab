<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Runtime\ContainerRunRequest;
use App\Runtime\ContainerRunResult;

interface ContainerRuntime
{
    public function run(ContainerRunRequest $request): ContainerRunResult;
}
