<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Plugins\PluginLifecycleManager;

final class PluginCommand extends PluginLifecycleCommand
{
    protected $signature = 'racklab {domain} {action} {slug} {--to-version=0}';

    protected $description = 'Run RackLab grouped commands such as "racklab plugin install <slug>".';

    public function handle(PluginLifecycleManager $plugins): int
    {
        $domain = $this->argument('domain');
        $action = $this->argument('action');
        $slug = $this->slug();

        if ($domain !== 'plugin' || ! is_string($action)) {
            $this->components->error('Only racklab plugin lifecycle commands are available through this entrypoint.');

            return self::FAILURE;
        }

        return match ($action) {
            'install' => $this->runLifecycleAction(
                function () use ($plugins, $slug): void {
                    $plugins->install($slug);
                },
                sprintf('Plugin [%s] installed.', $slug),
            ),
            'migrate' => $this->runLifecycleAction(
                function () use ($plugins, $slug): void {
                    $plugins->migrate($slug);
                },
                sprintf('Plugin [%s] migrated.', $slug),
            ),
            'enable' => $this->runLifecycleAction(
                function () use ($plugins, $slug): void {
                    $plugins->enable($slug);
                },
                sprintf('Plugin [%s] enabled. Restart RackLab web and worker processes to boot providers.', $slug),
            ),
            'disable' => $this->runLifecycleAction(
                function () use ($plugins, $slug): void {
                    $plugins->disable($slug);
                },
                sprintf('Plugin [%s] disabled. Restart RackLab web and worker processes to unload providers.', $slug),
            ),
            'rollback' => $this->runLifecycleAction(
                function () use ($plugins, $slug): void {
                    $plugins->rollback($slug);
                },
                sprintf('Plugin [%s] rolled back.', $slug),
            ),
            'uninstall' => $this->runLifecycleAction(
                function () use ($plugins, $slug): void {
                    $plugins->uninstall($slug);
                },
                sprintf('Plugin [%s] uninstalled.', $slug),
            ),
            default => $this->unknownAction($action),
        };
    }

    private function unknownAction(string $action): int
    {
        $this->components->error(sprintf('Unknown racklab plugin action [%s].', $action));

        return self::FAILURE;
    }
}
