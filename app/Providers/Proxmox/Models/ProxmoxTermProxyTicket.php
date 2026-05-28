<?php

declare(strict_types=1);

namespace App\Providers\Proxmox\Models;

final readonly class ProxmoxTermProxyTicket
{
    public function __construct(
        public string $ticket,
        public int $port,
        public string $upid,
        public string $user,
    ) {}
}
