<?php

declare(strict_types=1);

namespace App\Jobs\Maintenance;

use App\Runtime\PodmanStaleContainerReaper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled maintenance job: force-removes stale RackLab-labelled script
 * containers older than the configured max age. Replaces the
 * `racklab:reap-script-containers` invocation in the reconciler loop.
 */
final class ReapScriptContainersJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $maxAgeSeconds = 3600)
    {
        // Runs on `cleanup` (the script-runner pool) because reaping needs the
        // Podman socket, which only the runner pool exposes.
        $this->onQueue('cleanup');
    }

    public function handle(PodmanStaleContainerReaper $reaper): void
    {
        $reaper->reap($this->maxAgeSeconds);
    }
}
