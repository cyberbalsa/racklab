<?php

declare(strict_types=1);

namespace App\Deployments;

use App\Models\Deployment;
use App\Models\DeploymentOperation;
use App\Models\ProviderTask;

final readonly class PendingProviderTask
{
    public function __construct(
        public Deployment $deployment,
        public DeploymentOperation $operation,
        public ProviderTask $task,
    ) {}
}
