<?php

declare(strict_types=1);

namespace App\Providers\Proxmox\Models;

final readonly class ProxmoxTaskStatus
{
    public function __construct(
        public string $upid,
        public string $node,
        public string $status,
        public ?string $exitStatus,
    ) {}

    public function stopped(): bool
    {
        return $this->status === 'stopped';
    }

    public function successful(): bool
    {
        return $this->stopped() && $this->exitStatus === 'OK';
    }
}
