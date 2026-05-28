<?php

declare(strict_types=1);

namespace App\Providers\Proxmox\Models;

final readonly class ProxmoxVncProxyRequest
{
    public function __construct(
        public string $node,
        public int $vmid,
        public bool $websocket = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function formParams(): array
    {
        return [
            'websocket' => $this->websocket ? 1 : 0,
        ];
    }
}
