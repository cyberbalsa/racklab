<?php

declare(strict_types=1);

namespace App\Providers\Proxmox\Models;

final readonly class ClusterCapabilities
{
    public function __construct(
        public ProxmoxVersion $version,
        public bool $cloudInit,
        public bool $sdn,
        public bool $sdnDynamicLoadBalancer,
        public bool $backup,
        public bool $console,
    ) {}
}
