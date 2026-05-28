<?php

declare(strict_types=1);

namespace App\Deployments;

use App\Models\Deployment;
use App\Models\DeploymentOperation;

final readonly class DeploymentCreateResult
{
    public function __construct(
        public Deployment $deployment,
        public DeploymentOperation $operation,
        public bool $idempotentReplay,
    ) {}
}
