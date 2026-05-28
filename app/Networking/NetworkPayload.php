<?php

declare(strict_types=1);

namespace App\Networking;

use App\Models\Network;
use App\Models\Subnet;

final readonly class NetworkPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Network $network): array
    {
        $network->loadMissing(['networkOffering', 'subnets']);

        return [
            'id' => $network->getKey(),
            'tenant_id' => $network->tenant_id,
            'project_id' => $network->project_id,
            'network_offering_id' => $network->network_offering_id,
            'offering_slug' => $network->networkOffering?->slug,
            'name' => $network->name,
            'slug' => $network->slug,
            'state' => $network->state,
            'provider' => $network->provider,
            'reachability' => $network->reachability,
            'metadata' => $network->metadata ?? [],
            'subnets' => $network->subnets
                ->sortBy('cidr')
                ->values()
                ->map(static fn (Subnet $subnet): array => [
                    'id' => $subnet->getKey(),
                    'subnet_pool_id' => $subnet->subnet_pool_id,
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
}
