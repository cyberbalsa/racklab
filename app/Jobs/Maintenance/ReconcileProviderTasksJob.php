<?php

declare(strict_types=1);

namespace App\Jobs\Maintenance;

use App\Deployments\ProviderTaskReconciler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled maintenance job: resumes stale/abandoned provider tasks by UPID
 * without re-submitting the original provider operation. Replaces the
 * `racklab:reconcile-provider-tasks` invocation in the legacy reconciler shell
 * loop; driven by withSchedule() and run by Horizon.
 */
final class ReconcileProviderTasksJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(ProviderTaskReconciler $reconciler): void
    {
        $reconciler->reconcile();
    }
}
