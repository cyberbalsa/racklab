<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Deployments\ProviderTaskReconciler;
use Illuminate\Console\Command;

final class ReconcileProviderTasks extends Command
{
    protected $signature = 'racklab:reconcile-provider-tasks';

    protected $description = 'Resume stale RackLab provider tasks without re-submitting operations.';

    public function handle(ProviderTaskReconciler $reconciler): int
    {
        $count = $reconciler->reconcile();

        $this->components->info(sprintf('Reconciled %d provider tasks.', $count));

        return self::SUCCESS;
    }
}
