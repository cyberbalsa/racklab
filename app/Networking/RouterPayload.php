<?php

declare(strict_types=1);

namespace App\Networking;

use App\Models\Router;
use App\Models\RouterNetwork;

final readonly class RouterPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Router $router): array
    {
        $router->loadMissing(['interfaces.network', 'interfaces.subnet']);

        return [
            'id' => $router->getKey(),
            'tenant_id' => $router->tenant_id,
            'project_id' => $router->project_id,
            'name' => $router->name,
            'slug' => $router->slug,
            'state' => $router->state,
            'provider' => $router->provider,
            'provider_router_id' => $router->provider_router_id,
            'metadata' => $router->metadata ?? [],
            'interfaces' => $router->interfaces
                ->sortBy(static fn (RouterNetwork $interface): string => $interface->network_id)
                ->values()
                ->map(static fn (RouterNetwork $interface): array => [
                    'id' => $interface->getKey(),
                    'network_id' => $interface->network_id,
                    'network_slug' => $interface->network?->slug,
                    'subnet_id' => $interface->subnet_id,
                    'subnet_cidr' => $interface->subnet?->cidr,
                    'interface_ip' => $interface->interface_ip,
                    'state' => $interface->state,
                    'provider_binding' => $interface->provider_binding ?? [],
                ])
                ->all(),
        ];
    }
}
