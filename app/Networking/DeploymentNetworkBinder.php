<?php

declare(strict_types=1);

namespace App\Networking;

use App\Models\Deployment;
use App\Models\DeploymentNetworkBinding;
use App\Models\DeploymentOperation;
use App\Models\DeploymentResource;
use App\Models\NetworkOffering;
use App\Models\ProviderNetwork;
use App\Models\StackDefinition;

final readonly class DeploymentNetworkBinder
{
    public function __construct(private StackNetworkSpecExtractor $specs) {}

    public function attachForResource(
        Deployment $deployment,
        DeploymentOperation $operation,
        DeploymentResource $resource,
    ): void {
        /** @var StackDefinition $stack */
        $stack = StackDefinition::query()->whereKey($operation->stack_definition_id)->firstOrFail();

        foreach ($this->specs->forResource($stack, $resource) as $spec) {
            $offering = $this->offering($deployment->tenant_id, $spec);

            if (! $offering instanceof NetworkOffering) {
                continue;
            }

            /** @var ProviderNetwork $providerNetwork */
            $providerNetwork = $offering->providerNetwork;
            $management = $this->managementEndpoint($offering);

            DeploymentNetworkBinding::query()->updateOrCreate(
                [
                    'deployment_resource_id' => $resource->getKey(),
                    'nic_key' => $spec->key,
                ],
                [
                    'tenant_id' => $deployment->tenant_id,
                    'deployment_id' => $deployment->getKey(),
                    'network_offering_id' => $offering->getKey(),
                    'provider_network_id' => $providerNetwork->getKey(),
                    'component_key' => $resource->component_key,
                    'reachability' => $offering->reachability,
                    'state' => 'attached',
                    'provider' => $providerNetwork->provider,
                    'provider_binding' => $this->providerBinding($providerNetwork),
                    'management_host' => $management['host'],
                    'management_port' => $management['port'],
                    'metadata' => [
                        'offering_type' => $offering->offering_type,
                        'network_spec' => $spec->toArray(),
                        ...$this->metadata($offering),
                    ],
                ],
            );
        }
    }

    public function releaseForResource(DeploymentResource $resource): void
    {
        DeploymentNetworkBinding::query()
            ->where('deployment_resource_id', $resource->getKey())
            ->where('state', 'attached')
            ->update(['state' => 'released']);
    }

    public function releaseForDeployment(Deployment $deployment): void
    {
        DeploymentNetworkBinding::query()
            ->where('deployment_id', $deployment->getKey())
            ->where('state', 'attached')
            ->update(['state' => 'released']);
    }

    private function offering(string $tenantId, StackNetworkSpec $spec): ?NetworkOffering
    {
        $query = NetworkOffering::query()
            ->where('tenant_id', $tenantId)
            ->with('providerNetwork');

        if ($spec->offeringId !== null) {
            /** @var NetworkOffering|null $offering */
            $offering = $query->whereKey($spec->offeringId)->first();

            return $offering;
        }

        if ($spec->offeringSlug === null) {
            return null;
        }

        /** @var NetworkOffering|null $offering */
        $offering = $query->where('slug', $spec->offeringSlug)->first();

        return $offering;
    }

    /**
     * @return array{host: ?string, port: ?int}
     */
    private function managementEndpoint(NetworkOffering $offering): array
    {
        $metadata = $this->metadata($offering);
        $nat = is_array($metadata['nat'] ?? null) ? $this->stringKeyedArray($metadata['nat']) : [];
        $host = $nat['host'] ?? null;
        $port = $nat['port'] ?? null;

        return [
            'host' => is_string($host) && trim($host) !== '' ? $host : null,
            'port' => is_int($port) ? $port : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function providerBinding(ProviderNetwork $network): array
    {
        return array_filter([
            'provider' => $network->provider,
            'provider_cluster' => $network->provider_cluster,
            'network_type' => $network->network_type,
            'external_id' => $network->external_id,
            'bridge' => $network->bridge,
            'vlan_tag' => $network->vlan_tag,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(NetworkOffering $offering): array
    {
        return $offering->metadata ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }
}
