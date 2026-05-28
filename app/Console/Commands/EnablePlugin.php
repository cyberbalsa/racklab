<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Plugins\PluginLifecycleManager;

final class EnablePlugin extends PluginLifecycleCommand
{
    protected $signature = 'racklab:plugin-enable {slug}';

    protected $description = 'Enable a migrated RackLab plugin.';

    public function handle(PluginLifecycleManager $plugins): int
    {
        $slug = $this->slug();

        return $this->runLifecycleAction(
            function () use ($plugins, $slug): void {
                $plugins->enable($slug);
            },
            sprintf('Plugin [%s] enabled. Restart RackLab web and worker processes to boot providers.', $slug),
        );
    }
}
