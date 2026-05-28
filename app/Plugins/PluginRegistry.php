<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Models\PluginInstallation;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class PluginRegistry
{
    /**
     * @var array<string, bool>
     */
    private array $booted = [];

    public function __construct(private readonly Application $app) {}

    /**
     * @return array<string, PluginDescriptor>
     */
    public function availablePlugins(): array
    {
        $installedJson = base_path('vendor/composer/installed.json');

        if (! is_file($installedJson)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($installedJson), associative: true);

        if (! is_array($decoded)) {
            return [];
        }

        $packages = $decoded['packages'] ?? $decoded;

        if (! is_array($packages)) {
            return [];
        }

        $plugins = [];

        foreach ($packages as $package) {
            if (! is_array($package)) {
                continue;
            }

            /** @var array<string, mixed> $package */
            $descriptor = $this->descriptorFromPackage($package);

            if ($descriptor instanceof PluginDescriptor) {
                $plugins[$descriptor->slug] = $descriptor;
            }
        }

        ksort($plugins);

        return $plugins;
    }

    public function descriptor(string $slug): PluginDescriptor
    {
        return $this->availablePlugins()[$slug]
            ?? throw new RuntimeException(sprintf('RackLab plugin [%s] is not installed by Composer.', $slug));
    }

    /**
     * @return array<string, PluginDescriptor>
     */
    public function enabledPlugins(): array
    {
        if (! Schema::hasTable('plugin_installations')) {
            return [];
        }

        $available = $this->availablePlugins();
        $enabled = [];

        /** @var PluginInstallation $installation */
        foreach (PluginInstallation::query()->where('state', 'enabled')->orderBy('slug')->get() as $installation) {
            if (isset($available[$installation->slug])) {
                $enabled[$installation->slug] = $available[$installation->slug];
            }
        }

        return $enabled;
    }

    public function bootEnabledPlugins(): void
    {
        foreach ($this->enabledPlugins() as $descriptor) {
            $this->bootPlugin($descriptor);
        }
    }

    public function bootPlugin(PluginDescriptor $descriptor): void
    {
        if (isset($this->booted[$descriptor->slug])) {
            return;
        }

        if (! class_exists($descriptor->serviceProvider)) {
            throw new RuntimeException(sprintf(
                'RackLab plugin [%s] service provider [%s] was not autoloadable.',
                $descriptor->slug,
                $descriptor->serviceProvider,
            ));
        }

        $this->app->register($descriptor->serviceProvider);
        $this->booted[$descriptor->slug] = true;
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function descriptorFromPackage(array $package): ?PluginDescriptor
    {
        $extra = $package['extra'] ?? [];

        if (! is_array($extra)) {
            return null;
        }

        $racklab = $extra['racklab'] ?? [];

        if (! is_array($racklab) || ($racklab['plugin'] ?? false) !== true) {
            return null;
        }

        $packageName = is_string($package['name'] ?? null) ? $package['name'] : null;
        $slug = is_string($racklab['slug'] ?? null) ? $racklab['slug'] : $packageName;
        $version = is_string($package['version'] ?? null) ? $package['version'] : 'unknown';

        if (! is_string($packageName) || ! is_string($slug)) {
            return null;
        }

        $namespace = $this->firstAutoloadNamespace($package);
        $provider = is_string($racklab['service_provider'] ?? null)
            ? $racklab['service_provider']
            : ($namespace !== null ? $namespace.$this->providerBasename($packageName) : '');
        $manifestClass = is_string($racklab['manifest'] ?? null)
            ? $racklab['manifest']
            : ($namespace !== null ? $namespace.'Manifest' : null);
        $manifest = $this->manifest($manifestClass);

        return new PluginDescriptor(
            slug: $slug,
            packageName: $packageName,
            version: $version,
            serviceProvider: $provider,
            manifestClass: $manifestClass,
            name: $this->manifestString($manifest, 'name') ?? $packageName,
            description: $this->manifestString($manifest, 'description')
                ?? (is_string($package['description'] ?? null) ? $package['description'] : null),
        );
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function firstAutoloadNamespace(array $package): ?string
    {
        $autoload = $package['autoload'] ?? [];

        if (! is_array($autoload)) {
            return null;
        }

        $psr4 = $autoload['psr-4'] ?? [];

        if (! is_array($psr4)) {
            return null;
        }

        foreach (array_keys($psr4) as $namespace) {
            if (is_string($namespace) && $namespace !== '') {
                return $namespace;
            }
        }

        return null;
    }

    private function providerBasename(string $packageName): string
    {
        $suffix = str($packageName)->afterLast('/')->studly()->toString();

        return $suffix.'ServiceProvider';
    }

    private function manifest(?string $manifestClass): ?object
    {
        if (! is_string($manifestClass) || ! class_exists($manifestClass)) {
            return null;
        }

        $manifest = app($manifestClass);

        return is_object($manifest) ? $manifest : null;
    }

    private function manifestString(?object $manifest, string $method): ?string
    {
        if (! is_object($manifest) || ! method_exists($manifest, $method)) {
            return null;
        }

        $value = $manifest->{$method}();

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
