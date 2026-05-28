<?php

declare(strict_types=1);

namespace App\Jobs\Maintenance;

use App\Networking\ProviderDriftDetector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled maintenance job: scans providers for drift between RackLab's
 * recorded state and the real provider inventory. Replaces the
 * `racklab:detect-provider-drift` invocation in the reconciler loop; scans all
 * tenants/providers (null filters).
 */
final class DetectProviderDriftJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(ProviderDriftDetector $detector): void
    {
        $detector->detect();
    }
}
