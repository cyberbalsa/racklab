<?php

declare(strict_types=1);

namespace App\Providers\Proxmox;

use App\Providers\Proxmox\Contracts\ProxmoxClientContract;
use App\Providers\Proxmox\Models\ProxmoxTaskStatus;
use App\Providers\Proxmox\Models\ProxmoxTermProxyRequest;
use App\Providers\Proxmox\Models\ProxmoxTermProxyTicket;
use App\Providers\Proxmox\Models\ProxmoxVersion;
use App\Providers\Proxmox\Models\ProxmoxVmCloneRequest;
use App\Providers\Proxmox\Models\ProxmoxVmDeleteRequest;
use App\Providers\Proxmox\Models\ProxmoxVmPowerRequest;
use App\Providers\Proxmox\Models\ProxmoxVncProxyRequest;
use App\Providers\Proxmox\Models\ProxmoxVncTicket;
use RuntimeException;

final readonly class UnavailableProxmoxClient implements ProxmoxClientContract
{
    public function version(): ProxmoxVersion
    {
        $this->fail();
    }

    public function cloneVm(ProxmoxVmCloneRequest $request): string
    {
        $this->fail();
    }

    public function deleteVm(ProxmoxVmDeleteRequest $request): string
    {
        $this->fail();
    }

    public function powerVm(ProxmoxVmPowerRequest $request): string
    {
        $this->fail();
    }

    public function vncProxy(ProxmoxVncProxyRequest $request): ProxmoxVncTicket
    {
        $this->fail();
    }

    public function termProxy(ProxmoxTermProxyRequest $request): ProxmoxTermProxyTicket
    {
        $this->fail();
    }

    public function taskStatus(string $node, string $upid): ProxmoxTaskStatus
    {
        $this->fail();
    }

    public function taskLog(string $node, string $upid): array
    {
        $this->fail();
    }

    private function fail(): never
    {
        throw new RuntimeException('No Proxmox client is configured.');
    }
}
