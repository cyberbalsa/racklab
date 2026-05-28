<?php

declare(strict_types=1);

namespace App\Console\Proxy;

use App\Audit\AuditEventWriter;
use App\Domain\Console\ConsoleAccessGrant;
use App\Domain\Console\ConsoleKind;
use App\Models\Deployment;
use Carbon\CarbonImmutable;

final readonly class InMemoryProviderConsoleProxy implements ProviderConsoleProxy
{
    public const string DEFAULT_WEBSOCKET_URL = 'ws://racklab-console-proxy.invalid/ws';

    public function __construct(
        private AuditEventWriter $auditEvents,
    ) {}

    public function requestVncTicket(ConsoleAccessGrant $grant, Deployment $deployment): ProviderConsoleTicket
    {
        return $this->issue($grant, $deployment, ConsoleKind::Vnc);
    }

    public function requestTerminalProxy(ConsoleAccessGrant $grant, Deployment $deployment): ProviderConsoleTicket
    {
        return $this->issue($grant, $deployment, ConsoleKind::Terminal);
    }

    private function issue(ConsoleAccessGrant $grant, Deployment $deployment, ConsoleKind $expected): ProviderConsoleTicket
    {
        $this->guard($grant, $deployment, $expected);

        $ticket = $this->deterministicTicket($grant, $expected);

        $this->auditProxyRequest($grant, $deployment, $expected, 'allowed', null);

        return new ProviderConsoleTicket(
            ticket: $ticket,
            websocketUrl: self::DEFAULT_WEBSOCKET_URL,
            consoleKind: $expected,
            expiresAt: $grant->expiresAt,
            metadata: [
                'provider' => 'in-memory',
                'deployment_id' => $deployment->resourceId(),
            ],
        );
    }

    private function guard(ConsoleAccessGrant $grant, Deployment $deployment, ConsoleKind $expected): void
    {
        if ($grant->isExpired(CarbonImmutable::now())) {
            $this->auditProxyRequest($grant, $deployment, $expected, 'denied', 'grant_expired');

            throw new ProviderConsoleProxyException('Console grant has expired.', reason: 'grant_expired');
        }

        if ($grant->consoleKind !== $expected) {
            $this->auditProxyRequest($grant, $deployment, $expected, 'denied', 'console_kind_mismatch');

            throw new ProviderConsoleProxyException(
                sprintf('Console grant kind %s does not match requested %s.', $grant->consoleKind->value, $expected->value),
                reason: 'console_kind_mismatch',
            );
        }

        if ($grant->deploymentId !== $deployment->resourceId()) {
            $this->auditProxyRequest($grant, $deployment, $expected, 'denied', 'deployment_mismatch');

            throw new ProviderConsoleProxyException(
                'Console grant deployment does not match the deployment being opened.',
                reason: 'deployment_mismatch',
            );
        }

        if ($grant->tenantId !== $deployment->tenantId()) {
            $this->auditProxyRequest($grant, $deployment, $expected, 'denied', 'tenant_mismatch');

            throw new ProviderConsoleProxyException(
                'Console grant tenant does not match the deployment tenant.',
                reason: 'tenant_mismatch',
            );
        }
    }

    private function deterministicTicket(ConsoleAccessGrant $grant, ConsoleKind $kind): string
    {
        return 'in-memory-ticket-'.substr(hash('sha256', $grant->jti.':'.$kind->value), 0, 32);
    }

    private function auditProxyRequest(
        ConsoleAccessGrant $grant,
        Deployment $deployment,
        ConsoleKind $consoleKind,
        string $result,
        ?string $reason,
    ): void {
        $metadata = [
            'console_kind' => $consoleKind->value,
            'grant_id' => $grant->grantId,
            'jti' => $grant->jti,
            'provider' => 'in-memory',
        ];

        if ($reason !== null) {
            $metadata['reason'] = $reason;
        }

        $this->auditEvents->append([
            'event_type' => 'console.proxy.request',
            'action' => 'request_ticket',
            'result' => $result,
            'actor_type' => 'service',
            'actor_id' => 'provider-console-proxy',
            'actor_tenant' => $grant->tenantId,
            'resource_type' => $deployment->resourceType(),
            'resource_id' => $deployment->resourceId(),
            'resource_tenant' => $deployment->tenantId(),
            'target_tenant_set' => [$grant->tenantId, $deployment->tenantId()],
            'effective_permissions' => ['deployment.console.connect'],
            'metadata' => $metadata,
        ]);
    }
}
