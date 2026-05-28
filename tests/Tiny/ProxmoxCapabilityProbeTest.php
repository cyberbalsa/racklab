<?php

declare(strict_types=1);

use App\Providers\Proxmox\CapabilityProbe;
use App\Providers\Proxmox\Models\ProxmoxTaskStatus;
use App\Providers\Proxmox\Models\ProxmoxVersion;
use App\Providers\Proxmox\Models\ProxmoxVmCloneRequest;
use App\Providers\Proxmox\Models\ProxmoxVmDeleteRequest;
use App\Providers\Proxmox\Models\ProxmoxVmPowerRequest;

it('surfaces PVE 9.2 dynamic SDN load-balancer capability separately from older PVE lines', function (): void {
    $pve92 = new CapabilityProbe(new class extends Tests\Doubles\AbstractProxmoxClientDouble
    {
        public function version(): ProxmoxVersion
        {
            return ProxmoxVersion::fromStrings('9.2.1', '9.2', 'pve-manager/9.2-1/abcdef');
        }

        public function cloneVm(ProxmoxVmCloneRequest $request): string
        {
            return '';
        }

        public function deleteVm(ProxmoxVmDeleteRequest $request): string
        {
            return '';
        }

        public function powerVm(ProxmoxVmPowerRequest $request): string
        {
            return '';
        }

        public function taskStatus(string $node, string $upid): ProxmoxTaskStatus
        {
            return new ProxmoxTaskStatus($upid, $node, 'running', null);
        }

        public function taskLog(string $node, string $upid): array
        {
            return [];
        }
    });
    $pve84 = new CapabilityProbe(new class extends Tests\Doubles\AbstractProxmoxClientDouble
    {
        public function version(): ProxmoxVersion
        {
            return ProxmoxVersion::fromStrings('8.4.1', '8.4', 'pve-manager/8.4-1/abcdef');
        }

        public function cloneVm(ProxmoxVmCloneRequest $request): string
        {
            return '';
        }

        public function deleteVm(ProxmoxVmDeleteRequest $request): string
        {
            return '';
        }

        public function powerVm(ProxmoxVmPowerRequest $request): string
        {
            return '';
        }

        public function taskStatus(string $node, string $upid): ProxmoxTaskStatus
        {
            return new ProxmoxTaskStatus($upid, $node, 'running', null);
        }

        public function taskLog(string $node, string $upid): array
        {
            return [];
        }
    });

    expect($pve92->probe()->sdnDynamicLoadBalancer)->toBeTrue()
        ->and($pve92->probe()->cloudInit)->toBeTrue()
        ->and($pve84->probe()->sdn)->toBeTrue()
        ->and($pve84->probe()->sdnDynamicLoadBalancer)->toBeFalse();
});
