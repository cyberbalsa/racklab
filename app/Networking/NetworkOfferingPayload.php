<?php

declare(strict_types=1);

namespace App\Networking;

use App\Models\NetworkOffering;
use App\Models\ProviderNetwork;

final readonly class NetworkOfferingPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function make(NetworkOffering $offering): array
    {
        /** @var ProviderNetwork $network */
        $network = $offering->providerNetwork;

        return [
            'id' => $offering->getKey(),
            'tenant_id' => $offering->tenant_id,
            'name' => $offering->name,
            'slug' => $offering->slug,
            'offering_type' => $offering->offering_type,
            'reachability' => $offering->reachability,
            'metadata' => $offering->metadata ?? [],
            'provider_network' => [
                'id' => $network->getKey(),
                'name' => $network->name,
                'slug' => $network->slug,
                'provider' => $network->provider,
                'provider_cluster' => $network->provider_cluster,
                'network_type' => $network->network_type,
                'external_id' => $network->external_id,
                'bridge' => $network->bridge,
                'vlan_tag' => $network->vlan_tag,
            ],
        ];
    }
}
