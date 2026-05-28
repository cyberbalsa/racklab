<?php

declare(strict_types=1);

namespace App\Jobs\Maintenance;

use App\Deployments\DeploymentLeaseExpiry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled maintenance job: expires leased deployments that are past their
 * lease, releasing provider resources and recording transitions/audit/replay.
 * Replaces the `racklab:expire-deployments` invocation in the reconciler loop.
 */
final class ExpireDeploymentsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(DeploymentLeaseExpiry $expiry): void
    {
        $expiry->expireDue();
    }
}
