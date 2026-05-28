<?php

declare(strict_types=1);

namespace App\Providers\Proxmox\Models;

use InvalidArgumentException;

final readonly class ProxmoxVmPowerRequest
{
    public function __construct(
        public string $node,
        public int $vmid,
        public string $action,
    ) {
        if (! in_array($this->action, ['start', 'stop', 'reset', 'shutdown'], true)) {
            throw new InvalidArgumentException('Unsupported Proxmox power action.');
        }
    }
}
