<?php

declare(strict_types=1);

namespace App\Networking;

use App\Models\FloatingIp;

final readonly class FloatingIpPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function make(FloatingIp $floatingIp): array
    {
        $floatingIp->loadMissing('pool');

        return [
            'id' => $floatingIp->id,
            'tenant_id' => $floatingIp->tenant_id,
            'project_id' => $floatingIp->project_id,
            'floating_ip_pool_id' => $floatingIp->floating_ip_pool_id,
            'pool_slug' => $floatingIp->pool?->slug,
            'deployment_network_binding_id' => $floatingIp->deployment_network_binding_id,
            'address' => $floatingIp->address,
            'state' => $floatingIp->state,
            'provider' => $floatingIp->provider,
            'provider_binding' => $floatingIp->provider_binding ?? [],
            'metadata' => $floatingIp->metadata ?? [],
            'released_at' => $floatingIp->released_at?->toJSON(),
        ];
    }
}
