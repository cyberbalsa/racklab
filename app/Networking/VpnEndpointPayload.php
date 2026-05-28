<?php

declare(strict_types=1);

namespace App\Networking;

use App\Models\NetworkVpnEndpoint;
use App\Models\NetworkVpnEndpointBinding;

final readonly class VpnEndpointPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function make(NetworkVpnEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->getKey(),
            'tenant_id' => $endpoint->tenant_id,
            'project_id' => $endpoint->project_id,
            'deployment_id' => $endpoint->deployment_id,
            'network_id' => $endpoint->network_id,
            'vpn_public_ip_pool_id' => $endpoint->vpn_public_ip_pool_id,
            'name' => $endpoint->name,
            'state' => $endpoint->state,
            'provider' => $endpoint->provider,
            'capability' => $endpoint->capability,
            'created_at' => $endpoint->created_at?->toIso8601String(),
            'bindings' => $endpoint->bindings->map(
                static fn (NetworkVpnEndpointBinding $binding): array => [
                    'id' => $binding->getKey(),
                    'node' => $binding->node,
                    'public_ip' => $binding->public_ip,
                    'udp_port' => $binding->udp_port,
                    'state' => $binding->state,
                ],
            )->all(),
        ];
    }
}
