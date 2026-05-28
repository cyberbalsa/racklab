<?php

declare(strict_types=1);

namespace App\Providers\Proxmox\Models;

final readonly class ProxmoxVncTicket
{
    public function __construct(
        public string $ticket,
        public string $cert,
        public int $port,
        public string $upid,
        public string $user,
        public ?string $password = null,
    ) {}
}
