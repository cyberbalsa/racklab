<?php

declare(strict_types=1);

namespace Racklab\ConsoleProxmox;

use Illuminate\Support\ServiceProvider;
use Override;

final class ConsoleProxmoxServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        // The Proxmox console proxy implementation lives in core
        // (App\Providers\Proxmox\ProxmoxConsoleProxy). This plugin's
        // boot/register exist so that the racklab plugin lifecycle can gate
        // the capability without changing RACKLAB_CONSOLE_PROXY. Capability
        // wiring lands in M4 sub-slice 6 when the long-running console-proxy
        // process is introduced.
    }

    public function boot(): void
    {
        //
    }
}
