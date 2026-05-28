<?php

declare(strict_types=1);

namespace App\Networking;

use App\Models\FloatingIp;
use App\Models\Network;
use App\Models\ProviderDrift;
use App\Models\Router;
use App\Models\RouterNetwork;
use App\Models\SecurityGroup;
use App\Models\SecurityGroupRule;
use App\Models\Subnet;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final readonly class ProviderStateSnapshotter
{
    /**
     * @return list<Model>
     */
    public function resources(?string $tenantId = null, ?string $provider = null): array
    {
        return [
            ...$this->networkResources($tenantId, $provider),
            ...$this->routerResources($tenantId, $provider),
            ...$this->floatingIpResources($tenantId, $provider),
            ...$this->securityGroupResources($tenantId, $provider),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function expectedState(Model $resource): array
    {
        if ($resource instanceof Network) {
            return $this->expectedNetwork($resource);
        }

        if ($resource instanceof Router) {
            return $this->expectedRouter($resource);
        }

        if ($resource instanceof FloatingIp) {
            return $this->expectedFloatingIp($resource);
        }

        if ($resource instanceof SecurityGroup) {
            return $this->expectedSecurityGroup($resource);
        }

        throw new InvalidArgumentException(sprintf('Unsupported provider drift resource [%s].', $resource::class));
    }

    /**
     * @return array<string, mixed>
     */
    public function observedState(Model $resource): array
    {
        $metadata = $this->metadata($resource);
        $observed = $metadata['provider_observed_state'] ?? null;

        if (is_array($observed)) {
            return $this->stringKeyedArray($observed);
        }

        return $this->expectedState($resource);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function setObservedState(Model $resource, array $state): void
    {
        $metadata = $this->metadata($resource);
        $metadata['provider_observed_state'] = $state;
        $metadata['provider_repaired_at'] = now()->toISOString();

        $resource->forceFill(['metadata' => $metadata])->save();
    }

    public function clearObservedState(Model $resource): void
    {
        $metadata = $this->metadata($resource);
        unset($metadata['provider_observed_state']);
        $metadata['provider_adopted_at'] = now()->toISOString();

        $resource->forceFill(['metadata' => $metadata])->save();
    }

    /**
     * @param  array<string, mixed>  $observed
     */
    public function applyObservedState(Model $resource, array $observed): void
    {
        if ($resource instanceof Network) {
            $this->applyNetworkState($resource, $observed);

            return;
        }

        if ($resource instanceof Router) {
            $this->applyRouterState($resource, $observed);

            return;
        }

        if ($resource instanceof FloatingIp) {
            $this->applyFloatingIpState($resource, $observed);

            return;
        }

        if ($resource instanceof SecurityGroup) {
            $this->applySecurityGroupState($resource, $observed);

            return;
        }

        throw new InvalidArgumentException(sprintf('Unsupported provider drift resource [%s].', $resource::class));
    }

    public function findResource(ProviderDrift $drift): ?Model
    {
        return match ($drift->resource_type) {
            'network' => Network::query()->where('tenant_id', $drift->tenant_id)->whereKey($drift->resource_id)->first(),
            'router' => Router::query()->where('tenant_id', $drift->tenant_id)->whereKey($drift->resource_id)->first(),
            'floating_ip' => FloatingIp::query()->where('tenant_id', $drift->tenant_id)->whereKey($drift->resource_id)->first(),
            'security_group' => SecurityGroup::query()->where('tenant_id', $drift->tenant_id)->whereKey($drift->resource_id)->first(),
            default => null,
        };
    }

    public function resourceType(Model $resource): string
    {
        return match (true) {
            $resource instanceof Network => 'network',
            $resource instanceof Router => 'router',
            $resource instanceof FloatingIp => 'floating_ip',
            $resource instanceof SecurityGroup => 'security_group',
            default => throw new InvalidArgumentException(sprintf('Unsupported provider drift resource [%s].', $resource::class)),
        };
    }

    public function projectId(Model $resource): ?string
    {
        $projectId = $resource->getAttribute('project_id');

        return is_string($projectId) ? $projectId : null;
    }

    public function provider(Model $resource): string
    {
        $provider = $resource->getAttribute('provider');

        if (! is_string($provider) || $provider === '') {
            throw new InvalidArgumentException('Provider drift resources require a provider.');
        }

        return $provider;
    }

    public function label(Model $resource): string
    {
        $name = $resource->getAttribute('name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $address = $resource->getAttribute('address');

        if (is_string($address) && $address !== '') {
            return $address;
        }

        $key = $resource->getKey();

        return is_string($key) || is_int($key) ? (string) $key : 'unknown';
    }

    /**
     * @return list<Model>
     */
    private function networkResources(?string $tenantId, ?string $provider): array
    {
        $query = Network::query()->with('subnets');

        if (is_string($tenantId) && $tenantId !== '') {
            $query->where('tenant_id', $tenantId);
        }

        if (is_string($provider) && $provider !== '') {
            $query->where('provider', $provider);
        }

        /** @var list<Model> $resources */
        $resources = array_values($query->get()->all());

        return $resources;
    }

    /**
     * @return list<Model>
     */
    private function routerResources(?string $tenantId, ?string $provider): array
    {
        $query = Router::query()->with('interfaces');

        if (is_string($tenantId) && $tenantId !== '') {
            $query->where('tenant_id', $tenantId);
        }

        if (is_string($provider) && $provider !== '') {
            $query->where('provider', $provider);
        }

        /** @var list<Model> $resources */
        $resources = array_values($query->get()->all());

        return $resources;
    }

    /**
     * @return list<Model>
     */
    private function floatingIpResources(?string $tenantId, ?string $provider): array
    {
        $query = FloatingIp::query()->where('state', '!=', 'released');

        if (is_string($tenantId) && $tenantId !== '') {
            $query->where('tenant_id', $tenantId);
        }

        if (is_string($provider) && $provider !== '') {
            $query->where('provider', $provider);
        }

        /** @var list<Model> $resources */
        $resources = array_values($query->get()->all());

        return $resources;
    }

    /**
     * @return list<Model>
     */
    private function securityGroupResources(?string $tenantId, ?string $provider): array
    {
        $query = SecurityGroup::query()->with('rules');

        if (is_string($tenantId) && $tenantId !== '') {
            $query->where('tenant_id', $tenantId);
        }

        if (is_string($provider) && $provider !== '') {
            $query->where('provider', $provider);
        }

        /** @var list<Model> $resources */
        $resources = array_values($query->get()->all());

        return $resources;
    }

    /**
     * @return array<string, mixed>
     */
    private function expectedNetwork(Network $network): array
    {
        $network->loadMissing('subnets');

        return [
            'resource_type' => 'network',
            'id' => $network->getKey(),
            'name' => $network->name,
            'slug' => $network->slug,
            'state' => $network->state,
            'provider' => $network->provider,
            'reachability' => $network->reachability,
            'subnets' => $network->subnets
                ->sortBy('cidr')
                ->values()
                ->map(fn (Subnet $subnet): array => [
                    'cidr' => $subnet->cidr,
                    'ip_version' => $subnet->ip_version,
                    'gateway_ip' => $subnet->gateway_ip,
                    'dhcp_enabled' => $subnet->dhcp_enabled,
                    'allocation_pools' => $subnet->allocation_pools ?? [],
                    'dns_nameservers' => $subnet->dns_nameservers ?? [],
                    'host_routes' => $subnet->host_routes ?? [],
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function expectedRouter(Router $router): array
    {
        $router->loadMissing('interfaces');

        return [
            'resource_type' => 'router',
            'id' => $router->getKey(),
            'name' => $router->name,
            'slug' => $router->slug,
            'state' => $router->state,
            'provider' => $router->provider,
            'provider_router_id' => $router->provider_router_id,
            'interfaces' => $router->interfaces
                ->sortBy('network_id')
                ->values()
                ->map(fn (RouterNetwork $interface): array => [
                    'network_id' => $interface->network_id,
                    'subnet_id' => $interface->subnet_id,
                    'interface_ip' => $interface->interface_ip,
                    'state' => $interface->state,
                    'provider_binding' => $interface->provider_binding ?? [],
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function expectedFloatingIp(FloatingIp $floatingIp): array
    {
        return [
            'resource_type' => 'floating_ip',
            'id' => $floatingIp->getKey(),
            'address' => $floatingIp->address,
            'state' => $floatingIp->state,
            'provider' => $floatingIp->provider,
            'deployment_network_binding_id' => $floatingIp->deployment_network_binding_id,
            'provider_binding' => $floatingIp->provider_binding ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function expectedSecurityGroup(SecurityGroup $securityGroup): array
    {
        $securityGroup->loadMissing('rules');

        return [
            'resource_type' => 'security_group',
            'id' => $securityGroup->getKey(),
            'name' => $securityGroup->name,
            'slug' => $securityGroup->slug,
            'state' => $securityGroup->state,
            'provider' => $securityGroup->provider,
            'provider_security_group_id' => $securityGroup->provider_security_group_id,
            'rules' => $securityGroup->rules
                ->sortBy('position')
                ->values()
                ->map(fn (SecurityGroupRule $rule): array => [
                    'position' => $rule->position,
                    'direction' => $rule->direction,
                    'protocol' => $rule->protocol,
                    'ethertype' => $rule->ethertype,
                    'port_min' => $rule->port_min,
                    'port_max' => $rule->port_max,
                    'remote_cidr' => $rule->remote_cidr,
                    'state' => $rule->state,
                    'provider_rule_id' => $rule->provider_rule_id,
                    'provider_binding' => $rule->provider_binding ?? [],
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $observed
     */
    private function applyNetworkState(Network $network, array $observed): void
    {
        $updates = [];

        foreach (['state', 'reachability'] as $field) {
            if (is_string($observed[$field] ?? null)) {
                $updates[$field] = $observed[$field];
            }
        }

        if ($updates !== []) {
            $network->forceFill($updates)->save();
        }

        $this->clearObservedState($network);
    }

    /**
     * @param  array<string, mixed>  $observed
     */
    private function applyRouterState(Router $router, array $observed): void
    {
        $updates = [];

        if (is_string($observed['state'] ?? null)) {
            $updates['state'] = $observed['state'];
        }

        if (array_key_exists('provider_router_id', $observed)) {
            $updates['provider_router_id'] = is_string($observed['provider_router_id']) ? $observed['provider_router_id'] : null;
        }

        if ($updates !== []) {
            $router->forceFill($updates)->save();
        }

        $interfaces = $observed['interfaces'] ?? null;

        if (is_array($interfaces)) {
            $this->applyRouterInterfaces($router, $interfaces);
        }

        $this->clearObservedState($router);
    }

    /**
     * @param  array<string, mixed>  $observed
     */
    private function applyFloatingIpState(FloatingIp $floatingIp, array $observed): void
    {
        $updates = [];

        if (is_string($observed['state'] ?? null)) {
            $updates['state'] = $observed['state'];
        }

        if (array_key_exists('deployment_network_binding_id', $observed)) {
            $updates['deployment_network_binding_id'] = is_string($observed['deployment_network_binding_id'])
                ? $observed['deployment_network_binding_id']
                : null;
        }

        if (is_array($observed['provider_binding'] ?? null)) {
            $updates['provider_binding'] = $this->stringKeyedArray($observed['provider_binding']);
        }

        if (($updates['state'] ?? null) === 'released' && $floatingIp->released_at === null) {
            $updates['released_at'] = now();
        }

        if ($updates !== []) {
            $floatingIp->forceFill($updates)->save();
        }

        $this->clearObservedState($floatingIp);
    }

    /**
     * @param  array<string, mixed>  $observed
     */
    private function applySecurityGroupState(SecurityGroup $securityGroup, array $observed): void
    {
        $updates = [];

        if (is_string($observed['state'] ?? null)) {
            $updates['state'] = $observed['state'];
        }

        if (array_key_exists('provider_security_group_id', $observed)) {
            $updates['provider_security_group_id'] = is_string($observed['provider_security_group_id'])
                ? $observed['provider_security_group_id']
                : null;
        }

        if ($updates !== []) {
            $securityGroup->forceFill($updates)->save();
        }

        $rules = $observed['rules'] ?? null;

        if (is_array($rules)) {
            $this->replaceSecurityGroupRules($securityGroup, $rules);
        }

        $this->clearObservedState($securityGroup);
    }

    /**
     * @param  array<int|string, mixed>  $interfaces
     */
    private function applyRouterInterfaces(Router $router, array $interfaces): void
    {
        /** @var RouterNetwork $interface */
        foreach ($router->interfaces()->get() as $interface) {
            foreach ($interfaces as $observed) {
                if (! is_array($observed)) {
                    continue;
                }

                if (($observed['network_id'] ?? null) !== $interface->network_id) {
                    continue;
                }

                $updates = [];

                if (is_string($observed['state'] ?? null)) {
                    $updates['state'] = $observed['state'];
                }

                if (array_key_exists('interface_ip', $observed)) {
                    $updates['interface_ip'] = is_string($observed['interface_ip']) ? $observed['interface_ip'] : null;
                }

                if (is_array($observed['provider_binding'] ?? null)) {
                    $updates['provider_binding'] = $this->stringKeyedArray($observed['provider_binding']);
                }

                if ($updates !== []) {
                    $interface->forceFill($updates)->save();
                }
            }
        }
    }

    /**
     * @param  array<int|string, mixed>  $rules
     */
    private function replaceSecurityGroupRules(SecurityGroup $securityGroup, array $rules): void
    {
        SecurityGroupRule::query()->where('security_group_id', $securityGroup->getKey())->delete();

        foreach (array_values($rules) as $index => $rawRule) {
            if (! is_array($rawRule)) {
                continue;
            }

            $rule = $this->stringKeyedArray($rawRule);

            SecurityGroupRule::query()->create([
                'tenant_id' => $securityGroup->tenant_id,
                'security_group_id' => $securityGroup->getKey(),
                'position' => is_numeric($rule['position'] ?? null) ? (int) $rule['position'] : $index + 1,
                'direction' => is_string($rule['direction'] ?? null) ? $rule['direction'] : 'ingress',
                'protocol' => is_string($rule['protocol'] ?? null) ? $rule['protocol'] : 'any',
                'ethertype' => is_string($rule['ethertype'] ?? null) ? $rule['ethertype'] : 'IPv4',
                'port_min' => is_numeric($rule['port_min'] ?? null) ? (int) $rule['port_min'] : null,
                'port_max' => is_numeric($rule['port_max'] ?? null) ? (int) $rule['port_max'] : null,
                'remote_cidr' => is_string($rule['remote_cidr'] ?? null) ? $rule['remote_cidr'] : null,
                'state' => is_string($rule['state'] ?? null) ? $rule['state'] : 'active',
                'provider_rule_id' => is_string($rule['provider_rule_id'] ?? null) ? $rule['provider_rule_id'] : null,
                'provider_binding' => is_array($rule['provider_binding'] ?? null) ? $this->stringKeyedArray($rule['provider_binding']) : [],
                'metadata' => [],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(Model $resource): array
    {
        $metadata = $resource->getAttribute('metadata');

        return is_array($metadata) ? $this->stringKeyedArray($metadata) : [];
    }

    /**
     * @param  array<int|string, mixed>  $value
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $value): array
    {
        $normalized = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }
}
