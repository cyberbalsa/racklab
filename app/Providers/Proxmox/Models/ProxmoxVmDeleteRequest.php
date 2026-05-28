<?php

declare(strict_types=1);

namespace App\Providers\Proxmox\Models;

final readonly class ProxmoxVmDeleteRequest
{
    public function __construct(
        public string $node,
        public int $vmid,
        public bool $purge,
    ) {}

    /**
     * @return array<string, int>
     */
    public function query(): array
    {
        return [
            'purge' => $this->purge ? 1 : 0,
        ];
    }
}
