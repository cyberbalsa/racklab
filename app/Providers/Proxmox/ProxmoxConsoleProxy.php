<?php

declare(strict_types=1);

namespace App\Providers\Proxmox;

use App\Console\Proxy\ProviderConsoleProxy;
use App\Console\Proxy\ProviderConsoleProxyException;
use App\Console\Proxy\ProviderConsoleTicket;
use App\Domain\Console\ConsoleAccessGrant;
use App\Models\Deployment;

/**
 * Skeleton Proxmox console proxy.
 *
 * The real WebSocket forwarder + Proxmox `vncproxy` / `termproxy` plumbing
 * lands in M4 sub-slice 5. This skeleton fails closed so production cannot
 * accidentally bind a half-implemented proxy.
 */
final readonly class ProxmoxConsoleProxy implements ProviderConsoleProxy
{
    public function requestVncTicket(ConsoleAccessGrant $grant, Deployment $deployment): ProviderConsoleTicket
    {
        $this->notImplemented();
    }

    public function requestTerminalProxy(ConsoleAccessGrant $grant, Deployment $deployment): ProviderConsoleTicket
    {
        $this->notImplemented();
    }

    private function notImplemented(): never
    {
        throw new ProviderConsoleProxyException(
            'Proxmox console proxy is not yet implemented; ships in M4 sub-slice 5.',
            reason: 'proxmox_console_proxy_not_implemented',
        );
    }
}
