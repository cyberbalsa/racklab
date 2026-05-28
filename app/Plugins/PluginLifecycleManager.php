<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Models\PluginInstallation;
use App\Models\PluginMigrationRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class PluginLifecycleManager
{
    public function __construct(private PluginRegistry $registry) {}

    public function install(string $slug): PluginInstallation
    {
        $descriptor = $this->registry->descriptor($slug);

        return DB::transaction(function () use ($descriptor): PluginInstallation {
            /** @var PluginInstallation $installation */
            $installation = PluginInstallation::query()->updateOrCreate(
                ['slug' => $descriptor->slug],
                [
                    'package_name' => $descriptor->packageName,
                    'version' => $descriptor->version,
                    'state' => 'installed',
                    'service_provider' => $descriptor->serviceProvider,
                    'manifest_class' => $descriptor->manifestClass,
                    'name' => $descriptor->name,
                    'description' => $descriptor->description,
                    'installed_at' => now(),
                    'migrated_at' => null,
                    'enabled_at' => null,
                    'disabled_at' => null,
                ],
            );

            return $installation;
        });
    }

    public function migrate(string $slug): PluginInstallation
    {
        return DB::transaction(function () use ($slug): PluginInstallation {
            $installation = $this->installation($slug);

            if ($installation->state !== 'installed') {
                throw new RuntimeException(sprintf('Plugin [%s] must be installed before migrate.', $slug));
            }

            $installation->forceFill([
                'state' => 'migrated',
                'migrated_at' => now(),
            ])->save();
            $this->recordMigration($slug, 'up');

            return $installation;
        });
    }

    public function enable(string $slug): PluginInstallation
    {
        return DB::transaction(function () use ($slug): PluginInstallation {
            $installation = $this->installation($slug);

            if (! in_array($installation->state, ['migrated', 'disabled'], true)) {
                throw new RuntimeException(sprintf('Plugin [%s] must be migrated or disabled before enable.', $slug));
            }

            $installation->forceFill([
                'state' => 'enabled',
                'enabled_at' => now(),
                'disabled_at' => null,
            ])->save();

            return $installation;
        });
    }

    public function disable(string $slug): PluginInstallation
    {
        return DB::transaction(function () use ($slug): PluginInstallation {
            $installation = $this->installation($slug);

            if ($installation->state !== 'enabled') {
                throw new RuntimeException(sprintf('Plugin [%s] must be enabled before disable.', $slug));
            }

            $installation->forceFill([
                'state' => 'disabled',
                'disabled_at' => now(),
            ])->save();

            return $installation;
        });
    }

    public function rollback(string $slug): PluginInstallation
    {
        return DB::transaction(function () use ($slug): PluginInstallation {
            $installation = $this->installation($slug);

            if ($installation->state !== 'disabled') {
                throw new RuntimeException(sprintf('Plugin [%s] must be disabled before rollback.', $slug));
            }

            $installation->forceFill([
                'state' => 'migrated',
                'enabled_at' => null,
                'disabled_at' => null,
            ])->save();
            $this->recordMigration($slug, 'down');

            return $installation;
        });
    }

    public function uninstall(string $slug): void
    {
        DB::transaction(function () use ($slug): void {
            $installation = $this->installation($slug);

            if ($installation->state !== 'disabled') {
                throw new RuntimeException(sprintf('Plugin [%s] must be disabled before uninstall.', $slug));
            }

            $this->recordMigration($slug, 'down');
            $installation->delete();
        });
    }

    private function installation(string $slug): PluginInstallation
    {
        /** @var PluginInstallation|null $installation */
        $installation = PluginInstallation::query()->whereKey($slug)->first();

        if (! $installation instanceof PluginInstallation) {
            throw new RuntimeException(sprintf('Plugin [%s] is not installed in RackLab.', $slug));
        }

        return $installation;
    }

    private function recordMigration(string $slug, string $direction): void
    {
        PluginMigrationRecord::query()->create([
            'plugin_slug' => $slug,
            'direction' => $direction,
            'migration_version' => '0',
            'executed_at' => now(),
            'metadata' => [
                'runner' => 'racklab-core',
            ],
        ]);
    }
}
