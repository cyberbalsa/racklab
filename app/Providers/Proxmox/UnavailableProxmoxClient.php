<?php

declare(strict_types=1);

namespace App\Providers\Proxmox;

use App\Providers\Proxmox\Contracts\ProxmoxClientContract;
use App\Providers\Proxmox\Models\ProxmoxTaskStatus;
use App\Providers\Proxmox\Models\ProxmoxVersion;
use App\Providers\Proxmox\Models\ProxmoxVmCloneRequest;
use App\Providers\Proxmox\Models\ProxmoxVmDeleteRequest;
use App\Providers\Proxmox\Models\ProxmoxVmPowerRequest;
use RuntimeException;

final readonly class UnavailableProxmoxClient implements ProxmoxClientContract
{
    public function version(): ProxmoxVersion
    {
        throw new RuntimeException('No Proxmox client is configured.');
    }

    public function cloneVm(ProxmoxVmCloneRequest $request): string
    {
        throw new RuntimeException('No Proxmox client is configured.');
    }

    public function deleteVm(ProxmoxVmDeleteRequest $request): string
    {
        throw new RuntimeException('No Proxmox client is configured.');
    }

    public function powerVm(ProxmoxVmPowerRequest $request): string
    {
        throw new RuntimeException('No Proxmox client is configured.');
    }

    public function taskStatus(string $node, string $upid): ProxmoxTaskStatus
    {
        throw new RuntimeException('No Proxmox client is configured.');
    }

    public function taskLog(string $node, string $upid): array
    {
        throw new RuntimeException('No Proxmox client is configured.');
    }
}
