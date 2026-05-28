<?php

declare(strict_types=1);

namespace App\Providers\Proxmox\Contracts;

use App\Providers\Proxmox\Models\ProxmoxTaskStatus;
use App\Providers\Proxmox\Models\ProxmoxTermProxyRequest;
use App\Providers\Proxmox\Models\ProxmoxTermProxyTicket;
use App\Providers\Proxmox\Models\ProxmoxVersion;
use App\Providers\Proxmox\Models\ProxmoxVmCloneRequest;
use App\Providers\Proxmox\Models\ProxmoxVmDeleteRequest;
use App\Providers\Proxmox\Models\ProxmoxVmPowerRequest;
use App\Providers\Proxmox\Models\ProxmoxVncProxyRequest;
use App\Providers\Proxmox\Models\ProxmoxVncTicket;

interface ProxmoxClientContract
{
    public function version(): ProxmoxVersion;

    public function cloneVm(ProxmoxVmCloneRequest $request): string;

    public function deleteVm(ProxmoxVmDeleteRequest $request): string;

    public function powerVm(ProxmoxVmPowerRequest $request): string;

    public function vncProxy(ProxmoxVncProxyRequest $request): ProxmoxVncTicket;

    public function termProxy(ProxmoxTermProxyRequest $request): ProxmoxTermProxyTicket;

    public function taskStatus(string $node, string $upid): ProxmoxTaskStatus;

    /**
     * @return list<string>
     */
    public function taskLog(string $node, string $upid): array;
}
