<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Deployments\DeploymentLeaseExpiry;
use Illuminate\Console\Command;

final class ExpireDeployments extends Command
{
    protected $signature = 'racklab:expire-deployments';

    protected $description = 'Expire RackLab deployments whose lease has elapsed.';

    public function handle(DeploymentLeaseExpiry $expiry): int
    {
        $expired = $expiry->expireDue();

        $this->components->info(sprintf('Expired %d deployments.', $expired));

        return self::SUCCESS;
    }
}
