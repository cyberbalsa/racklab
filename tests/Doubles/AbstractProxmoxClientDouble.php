<?php

declare(strict_types=1);

namespace Tests\Doubles;

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
use BadMethodCallException;

/**
 * Default-fail test double for ProxmoxClientContract. Override only the
 * methods a given test exercises; every unoverridden method throws so an
 * accidental call is loud rather than silent.
 *
 * Anonymous classes in tests can `extends AbstractProxmoxClientDouble`
 * instead of `implements ProxmoxClientContract` to avoid restating every
 * method when only one or two are exercised. Tests that need to inspect
 * the full contract still implement directly.
 */
abstract class AbstractProxmoxClientDouble implements ProxmoxClientContract
{
    public function version(): ProxmoxVersion
    {
        throw new BadMethodCallException(static::class.': version() not stubbed.');
    }

    public function cloneVm(ProxmoxVmCloneRequest $request): string
    {
        throw new BadMethodCallException(static::class.': cloneVm() not stubbed.');
    }

    public function deleteVm(ProxmoxVmDeleteRequest $request): string
    {
        throw new BadMethodCallException(static::class.': deleteVm() not stubbed.');
    }

    public function powerVm(ProxmoxVmPowerRequest $request): string
    {
        throw new BadMethodCallException(static::class.': powerVm() not stubbed.');
    }

    public function vncProxy(ProxmoxVncProxyRequest $request): ProxmoxVncTicket
    {
        throw new BadMethodCallException(static::class.': vncProxy() not stubbed.');
    }

    public function termProxy(ProxmoxTermProxyRequest $request): ProxmoxTermProxyTicket
    {
        throw new BadMethodCallException(static::class.': termProxy() not stubbed.');
    }

    public function taskStatus(string $node, string $upid): ProxmoxTaskStatus
    {
        throw new BadMethodCallException(static::class.': taskStatus() not stubbed.');
    }

    public function taskLog(string $node, string $upid): array
    {
        throw new BadMethodCallException(static::class.': taskLog() not stubbed.');
    }
}
