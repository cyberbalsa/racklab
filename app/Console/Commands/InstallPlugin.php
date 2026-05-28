<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Plugins\PluginLifecycleManager;

final class InstallPlugin extends PluginLifecycleCommand
{
    protected $signature = 'racklab:plugin-install {slug}';

    protected $description = 'Record a Composer-installed RackLab plugin as installed.';

    public function handle(PluginLifecycleManager $plugins): int
    {
        $slug = $this->slug();

        return $this->runLifecycleAction(
            function () use ($plugins, $slug): void {
                $plugins->install($slug);
            },
            sprintf('Plugin [%s] installed.', $slug),
        );
    }
}
