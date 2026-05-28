<?php

declare(strict_types=1);

namespace App\Deployments;

use App\Models\Deployment;
use App\Models\DeploymentNetworkBinding;
use App\Models\DeploymentOperation;
use App\Models\DeploymentResource;

final readonly class DeploymentPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function make(
        Deployment $deployment,
        ?DeploymentOperation $operation = null,
        bool $idempotentReplay = false,
    ): array {
        $deployment->loadMissing(['resources.networkBindings.networkOffering']);

        $payload = [
            'id' => $deployment->getKey(),
            'tenant_id' => $deployment->tenant_id,
            'project_id' => $deployment->project_id,
            'stack_definition_id' => $deployment->stack_definition_id,
            'name' => $deployment->name,
            'state' => $deployment->state,
            'provider' => $deployment->provider,
            'lease_expires_at' => $deployment->lease_expires_at?->toJSON(),
            'resources' => $deployment->resources
                ->sortBy('component_key')
                ->values()
                ->map(static fn (DeploymentResource $resource): array => [
                    'id' => $resource->getKey(),
                    'component_key' => $resource->component_key,
                    'kind' => $resource->kind,
                    'state' => $resource->state,
                    'provider' => $resource->provider,
                    'provider_resource_id' => $resource->provider_resource_id,
                    'networks' => $resource->networkBindings
                        ->sortBy('nic_key')
                        ->values()
                        ->map(static fn (DeploymentNetworkBinding $binding): array => [
                            'id' => $binding->getKey(),
                            'nic_key' => $binding->nic_key,
                            'offering_id' => $binding->network_offering_id,
                            'offering_slug' => $binding->networkOffering?->slug,
                            'reachability' => $binding->reachability,
                            'state' => $binding->state,
                            'provider' => $binding->provider,
                            'provider_binding' => $binding->provider_binding ?? [],
                            'management_host' => $binding->management_host,
                            'management_port' => $binding->management_port,
                        ])
                        ->all(),
                ])
                ->all(),
        ];

        if ($operation instanceof DeploymentOperation) {
            $payload['operation'] = [
                'id' => $operation->getKey(),
                'kind' => $operation->kind,
                'state' => $operation->state,
                'idempotency_key' => $operation->idempotency_key,
            ];
        }

        if ($idempotentReplay) {
            $payload['idempotent_replay'] = true;
        }

        return $payload;
    }
}
