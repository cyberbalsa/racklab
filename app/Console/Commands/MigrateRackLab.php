<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PluginInstallation;
use App\Plugins\PluginLifecycleManager;
use Illuminate\Console\Command;
use Throwable;

final class MigrateRackLab extends Command
{
    protected $signature = 'racklab:migrate {--no-seed : Skip the RackLab bootstrap seed.} {--skip-plugins : Skip installed plugin migrations.}';

    protected $description = 'Run RackLab core migrations, bootstrap seed, and installed plugin migrations.';

    public function handle(PluginLifecycleManager $plugins): int
    {
        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        if ($this->option('no-seed') !== true && $this->call('db:seed', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        if ($this->option('skip-plugins') === true) {
            return self::SUCCESS;
        }

        foreach ($this->installedPluginSlugs() as $slug) {
            try {
                $plugins->migrate($slug);
            } catch (Throwable $throwable) {
                $this->components->error(sprintf('Plugin [%s] migration failed: %s', $slug, $throwable->getMessage()));

                return self::FAILURE;
            }

            $this->components->info(sprintf('Plugin [%s] migrated.', $slug));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function installedPluginSlugs(): array
    {
        $slugs = PluginInstallation::query()
            ->where('state', 'installed')
            ->orderBy('slug')
            ->get(['slug'])
            ->map(static fn (PluginInstallation $installation): string => $installation->slug)
            ->all();

        return array_values($slugs);
    }
}
