<?php

declare(strict_types=1);

namespace App\Jobs\Maintenance;

use App\Deployments\ProviderTaskReconciler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled maintenance job: resumes stale/abandoned provider tasks by UPID
 * without re-submitting the original provider operation. Replaces the
 * `racklab:reconcile-provider-tasks` invocation in the legacy reconciler shell
 * loop; driven by withSchedule() and run by Horizon.
 */
final class ReconcileProviderTasksJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Lock TTL safety net if a run dies without releasing; the lock is normally
     * released when the job completes. Keeps the scheduler from piling up
     * duplicate runs (which scheduler-level withoutOverlapping does not cover,
     * since it only guards enqueue, not execution).
     */
    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function uniqueId(): string
    {
        return self::class;
    }

    public function handle(ProviderTaskReconciler $reconciler): void
    {
        $reconciler->reconcile();
    }
}
