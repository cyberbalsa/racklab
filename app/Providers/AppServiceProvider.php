<?php

declare(strict_types=1);

namespace App\Providers;

use App\Backup\BackupProcessRunner;
use App\Backup\NativeBackupProcessRunner;
use App\Backup\NativeRedisBackupClient;
use App\Backup\RedisBackupClient;
use App\Console\Proxy\InMemoryProviderConsoleProxy;
use App\Console\Proxy\ProviderConsoleProxy;
use App\Console\Proxy\UnavailableProviderConsoleProxy;
use App\Contracts\ContainerRuntime;
use App\Docs\Refs\Resolving\Core\CourseRefResolver;
use App\Docs\Refs\Resolving\Core\DeploymentRefResolver;
use App\Docs\Refs\Resolving\Core\NetworkRefResolver;
use App\Docs\Refs\Resolving\Core\PluginRefResolver;
use App\Docs\Refs\Resolving\Core\ProjectRefResolver;
use App\Docs\Refs\Resolving\Core\ScriptRefResolver;
use App\Docs\Refs\Resolving\ProbabilityRefResolveAuditSampler;
use App\Docs\Refs\Resolving\RefResolveAuditSampler;
use App\Docs\Refs\Resolving\RefResolverRegistry;
use App\Domain\Rbac\RolePermissionLookup;
use App\Domain\Tenancy\RoleBindingRepository;
use App\Domain\Tenancy\TenantContextStore;
use App\Networking\PlaceholderVpnClientProfileGenerator;
use App\Networking\VpnClientProfileGenerator;
use App\Plugins\HookDispatcher;
use App\Plugins\PluginRegistry;
use App\Providers\Proxmox\Contracts\ProxmoxClientContract;
use App\Providers\Proxmox\GuzzleProxmoxClient;
use App\Providers\Proxmox\ProxmoxConsoleProxy;
use App\Providers\Proxmox\ProxmoxEndpointConfig;
use App\Providers\Proxmox\UnavailableProxmoxClient;
use App\Rbac\EloquentRolePermissionLookup;
use App\Runtime\ContainerProcessRunner;
use App\Runtime\FakeContainerRuntime;
use App\Runtime\NativeContainerProcessRunner;
use App\Runtime\PodmanCommandBuilder;
use App\Runtime\PodmanContainerRuntime;
use App\Runtime\UnavailableContainerRuntime;
use App\Tenancy\EloquentRoleBindingRepository;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;
use Override;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        $this->app->singleton(TenantContextStore::class);
        $this->app->singleton(HookDispatcher::class);
        $this->app->singleton(RefResolverRegistry::class, fn (): RefResolverRegistry => new RefResolverRegistry(
            $this->app->make(HookDispatcher::class),
            [
                $this->app->make(DeploymentRefResolver::class),
                $this->app->make(ProjectRefResolver::class),
                $this->app->make(CourseRefResolver::class),
                $this->app->make(NetworkRefResolver::class),
                $this->app->make(ScriptRefResolver::class),
                $this->app->make(PluginRefResolver::class),
            ],
        ));
        $this->app->bind(RefResolveAuditSampler::class, function (): RefResolveAuditSampler {
            $rate = config('docs.ref_resolve_audit_sample_rate', 0.1);

            return new ProbabilityRefResolveAuditSampler(is_numeric($rate) ? (float) $rate : 0.1);
        });
        $this->app->bind(RoleBindingRepository::class, EloquentRoleBindingRepository::class);
        $this->app->bind(RolePermissionLookup::class, EloquentRolePermissionLookup::class);
        $this->app->bind(ProxmoxClientContract::class, function (): ProxmoxClientContract {
            $rawConfig = config('racklab.proxmox');

            if (! is_array($rawConfig) || ($rawConfig['enabled'] ?? false) !== true) {
                return new UnavailableProxmoxClient;
            }

            $config = [];

            foreach ($rawConfig as $key => $value) {
                if (is_string($key)) {
                    $config[$key] = $value;
                }
            }

            return new GuzzleProxmoxClient(ProxmoxEndpointConfig::fromArray($config), new Client);
        });
        $this->app->bind(BackupProcessRunner::class, NativeBackupProcessRunner::class);
        $this->app->bind(RedisBackupClient::class, NativeRedisBackupClient::class);
        $this->app->bind(VpnClientProfileGenerator::class, PlaceholderVpnClientProfileGenerator::class);
        $this->app->bind(ContainerProcessRunner::class, NativeContainerProcessRunner::class);
        $this->app->bind(PodmanCommandBuilder::class, fn (): PodmanCommandBuilder => new PodmanCommandBuilder($this->podmanBinary()));
        $this->app->bind(ContainerRuntime::class, fn (): ContainerRuntime => match (config('racklab.container_runtime')) {
            'podman' => app(PodmanContainerRuntime::class),
            'fake' => app(FakeContainerRuntime::class),
            default => app(UnavailableContainerRuntime::class),
        });
        $this->app->bind(ProviderConsoleProxy::class, fn (): ProviderConsoleProxy => match (config('racklab.console.proxy')) {
            'in-memory' => app(InMemoryProviderConsoleProxy::class),
            'proxmox' => $this->resolveProxmoxConsoleProxy(),
            default => app(UnavailableProviderConsoleProxy::class),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Proxmox console requires both the env-var (`RACKLAB_CONSOLE_PROXY=proxmox`)
     * AND the `racklab/console-proxmox` plugin to be enabled. The plugin
     * lifecycle is the operator-facing gate; the env-var alone is not enough
     * to start handing out Proxmox tickets, so disabling/uninstalling the
     * plugin reliably removes the capability.
     */
    private function resolveProxmoxConsoleProxy(): ProviderConsoleProxy
    {
        try {
            $registry = $this->app->make(PluginRegistry::class);
            $enabled = $registry->enabledPlugins();
        } catch (Throwable) {
            return $this->app->make(UnavailableProviderConsoleProxy::class);
        }

        if (! isset($enabled['racklab/console-proxmox'])) {
            return $this->app->make(UnavailableProviderConsoleProxy::class);
        }

        return $this->app->make(ProxmoxConsoleProxy::class);
    }

    /**
     * @return list<string>
     */
    private function podmanBinary(): array
    {
        $binary = config('racklab.podman.binary', 'podman');

        if (! is_string($binary) || trim($binary) === '') {
            return ['podman'];
        }

        $parts = preg_split('/\s+/', trim($binary));

        if ($parts === false) {
            return ['podman'];
        }

        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

        return $parts === [] ? ['podman'] : $parts;
    }
}
