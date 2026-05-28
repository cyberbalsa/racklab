<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Plugins\PluginLifecycleManager;

final class UninstallPlugin extends PluginLifecycleCommand
{
    protected $signature = 'racklab:plugin-uninstall {slug}';

    protected $description = 'Remove disabled RackLab plugin lifecycle metadata.';

    public function handle(PluginLifecycleManager $plugins): int
    {
        $slug = $this->slug();

        return $this->runLifecycleAction(
            function () use ($plugins, $slug): void {
                $plugins->uninstall($slug);
            },
            sprintf('Plugin [%s] uninstalled.', $slug),
        );
    }
}
