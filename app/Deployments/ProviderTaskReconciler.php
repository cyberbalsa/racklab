<?php

declare(strict_types=1);

namespace App\Deployments;

use App\Models\ProviderTask;

final readonly class ProviderTaskReconciler
{
    public function __construct(private FakeProviderTaskRunner $runner) {}

    public function reconcile(): int
    {
        $reconciled = 0;

        /** @var ProviderTask $task */
        foreach ($this->staleTasks() as $task) {
            $this->runner->run($task->id);
            $reconciled++;
        }

        return $reconciled;
    }

    /**
     * @return iterable<int, ProviderTask>
     */
    private function staleTasks(): iterable
    {
        return ProviderTask::query()
            ->whereIn('state', ['pending', 'running'])
            ->where('updated_at', '<=', now()->subMinute())
            ->orderBy('updated_at')
            ->get();
    }
}
