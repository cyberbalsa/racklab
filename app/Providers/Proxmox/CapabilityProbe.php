<?php

declare(strict_types=1);

namespace App\Providers\Proxmox;

use App\Providers\Proxmox\Contracts\ProxmoxClientContract;
use App\Providers\Proxmox\Models\ClusterCapabilities;

final readonly class CapabilityProbe
{
    public function __construct(private ProxmoxClientContract $client) {}

    public function probe(): ClusterCapabilities
    {
        $version = $this->client->version();

        return new ClusterCapabilities(
            version: $version,
            cloudInit: $version->supportsAtLeast(7, 0),
            sdn: $version->supportsAtLeast(8, 0),
            sdnDynamicLoadBalancer: $version->supportsAtLeast(9, 2),
            backup: $version->supportsAtLeast(7, 0),
            console: $version->supportsAtLeast(7, 0),
        );
    }
}
