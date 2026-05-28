<?php

declare(strict_types=1);

namespace App\Console\Proxy;

use App\Domain\Console\ConsoleAccessGrant;
use App\Models\Deployment;

interface ProviderConsoleProxy
{
    /**
     * Issue a VNC ticket for the deployment referenced by the grant.
     *
     * Implementations must reject expired grants, grants whose deployment_id
     * does not match the supplied deployment, and grants whose console_kind
     * is not ConsoleKind::Vnc, by throwing ProviderConsoleProxyException.
     */
    public function requestVncTicket(ConsoleAccessGrant $grant, Deployment $deployment): ProviderConsoleTicket;

    /**
     * Issue a terminal/serial proxy ticket for the deployment referenced by the grant.
     *
     * Same rejection invariants as {@see requestVncTicket()}, but the grant's
     * console_kind must be ConsoleKind::Terminal.
     */
    public function requestTerminalProxy(ConsoleAccessGrant $grant, Deployment $deployment): ProviderConsoleTicket;
}
