<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Plugins\PluginLifecycleManager;

final class RollbackPlugin extends PluginLifecycleCommand
{
    protected $signature = 'racklab:plugin-rollback {slug} {--to-version=0}';

    protected $description = 'Rollback migrations for a disabled RackLab plugin.';

    public function handle(PluginLifecycleManager $plugins): int
    {
        $slug = $this->slug();

        return $this->runLifecycleAction(
            function () use ($plugins, $slug): void {
                $plugins->rollback($slug);
            },
            sprintf('Plugin [%s] rolled back.', $slug),
        );
    }
}
