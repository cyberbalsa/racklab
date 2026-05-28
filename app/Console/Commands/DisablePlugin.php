<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Plugins\PluginLifecycleManager;

final class DisablePlugin extends PluginLifecycleCommand
{
    protected $signature = 'racklab:plugin-disable {slug}';

    protected $description = 'Disable an enabled RackLab plugin.';

    public function handle(PluginLifecycleManager $plugins): int
    {
        $slug = $this->slug();

        return $this->runLifecycleAction(
            function () use ($plugins, $slug): void {
                $plugins->disable($slug);
            },
            sprintf('Plugin [%s] disabled. Restart RackLab web and worker processes to unload providers.', $slug),
        );
    }
}
