<?php

declare(strict_types=1);

namespace App\Providers\Proxmox\Contracts;

use App\Providers\Proxmox\Models\ProxmoxTaskStatus;
use App\Providers\Proxmox\Models\ProxmoxVersion;
use App\Providers\Proxmox\Models\ProxmoxVmCloneRequest;
use App\Providers\Proxmox\Models\ProxmoxVmDeleteRequest;
use App\Providers\Proxmox\Models\ProxmoxVmPowerRequest;

interface ProxmoxClientContract
{
    public function version(): ProxmoxVersion;

    public function cloneVm(ProxmoxVmCloneRequest $request): string;

    public function deleteVm(ProxmoxVmDeleteRequest $request): string;

    public function powerVm(ProxmoxVmPowerRequest $request): string;

    public function taskStatus(string $node, string $upid): ProxmoxTaskStatus;

    /**
     * @return list<string>
     */
    public function taskLog(string $node, string $upid): array;
}
