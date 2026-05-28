<?php

declare(strict_types=1);

namespace App\Console\Proxy;

use App\Domain\Console\ConsoleAccessGrant;
use App\Models\Deployment;

final readonly class UnavailableProviderConsoleProxy implements ProviderConsoleProxy
{
    public function requestVncTicket(ConsoleAccessGrant $grant, Deployment $deployment): ProviderConsoleTicket
    {
        $this->fail();
    }

    public function requestTerminalProxy(ConsoleAccessGrant $grant, Deployment $deployment): ProviderConsoleTicket
    {
        $this->fail();
    }

    private function fail(): never
    {
        throw new ProviderConsoleProxyException(
            'No provider console proxy is configured. Set RACKLAB_CONSOLE_PROXY=in-memory or =proxmox to enable consoles.',
            reason: 'console_proxy_unavailable',
        );
    }
}
