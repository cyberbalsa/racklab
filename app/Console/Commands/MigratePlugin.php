<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Plugins\PluginLifecycleManager;

final class MigratePlugin extends PluginLifecycleCommand
{
    protected $signature = 'racklab:plugin-migrate {slug}';

    protected $description = 'Run forward migrations for a RackLab plugin.';

    public function handle(PluginLifecycleManager $plugins): int
    {
        $slug = $this->slug();

        return $this->runLifecycleAction(
            function () use ($plugins, $slug): void {
                $plugins->migrate($slug);
            },
            sprintf('Plugin [%s] migrated.', $slug),
        );
    }
}
