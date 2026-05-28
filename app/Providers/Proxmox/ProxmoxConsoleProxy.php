<?php

declare(strict_types=1);

namespace App\Providers\Proxmox;

use App\Audit\AuditEventWriter;
use App\Console\Proxy\ProviderConsoleProxy;
use App\Console\Proxy\ProviderConsoleProxyException;
use App\Console\Proxy\ProviderConsoleTicket;
use App\Domain\Console\ConsoleAccessGrant;
use App\Domain\Console\ConsoleKind;
use App\Models\Deployment;
use App\Models\DeploymentResource;
use App\Providers\Proxmox\Contracts\ProxmoxClientContract;
use App\Providers\Proxmox\Models\ProxmoxTermProxyRequest;
use App\Providers\Proxmox\Models\ProxmoxVncProxyRequest;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

final readonly class ProxmoxConsoleProxy implements ProviderConsoleProxy
{
    public function __construct(
        private ProxmoxClientContract $client,
        private AuditEventWriter $auditEvents,
    ) {}

    public function requestVncTicket(ConsoleAccessGrant $grant, Deployment $deployment): ProviderConsoleTicket
    {
        $this->guard($grant, $deployment, ConsoleKind::Vnc);
        [$node, $vmid] = $this->resolveNodeAndVmid($grant, $deployment);

        try {
            $ticket = $this->client->vncProxy(new ProxmoxVncProxyRequest(
                node: $node,
                vmid: $vmid,
                websocket: true,
            ));
        } catch (Throwable $throwable) {
            $this->audit($grant, $deployment, ConsoleKind::Vnc, 'denied', 'provider_error', ['node' => $node, 'vmid' => $vmid, 'exception' => $throwable::class]);

            throw new ProviderConsoleProxyException(
                sprintf('Proxmox vncproxy request failed: %s', $throwable->getMessage()),
                reason: 'provider_error',
            );
        }

        $this->audit($grant, $deployment, ConsoleKind::Vnc, 'allowed', null, [
            'node' => $node,
            'vmid' => $vmid,
            'upid' => $ticket->upid,
            'port' => $ticket->port,
        ]);

        return new ProviderConsoleTicket(
            ticket: $ticket->ticket,
            websocketUrl: $this->buildWebsocketUrl($node, $vmid, $ticket->port, $ticket->ticket),
            consoleKind: ConsoleKind::Vnc,
            expiresAt: $grant->expiresAt,
            metadata: [
                'provider' => 'proxmox',
                'node' => $node,
                'vmid' => $vmid,
                'port' => $ticket->port,
                'upid' => $ticket->upid,
                'user' => $ticket->user,
            ],
        );
    }

    public function requestTerminalProxy(ConsoleAccessGrant $grant, Deployment $deployment): ProviderConsoleTicket
    {
        $this->guard($grant, $deployment, ConsoleKind::Terminal);
        [$node, $vmid] = $this->resolveNodeAndVmid($grant, $deployment);

        try {
            $ticket = $this->client->termProxy(new ProxmoxTermProxyRequest(
                node: $node,
                vmid: $vmid,
            ));
        } catch (Throwable $throwable) {
            $this->audit($grant, $deployment, ConsoleKind::Terminal, 'denied', 'provider_error', ['node' => $node, 'vmid' => $vmid, 'exception' => $throwable::class]);

            throw new ProviderConsoleProxyException(
                sprintf('Proxmox termproxy request failed: %s', $throwable->getMessage()),
                reason: 'provider_error',
            );
        }

        $this->audit($grant, $deployment, ConsoleKind::Terminal, 'allowed', null, [
            'node' => $node,
            'vmid' => $vmid,
            'upid' => $ticket->upid,
            'port' => $ticket->port,
        ]);

        return new ProviderConsoleTicket(
            ticket: $ticket->ticket,
            websocketUrl: $this->buildWebsocketUrl($node, $vmid, $ticket->port, $ticket->ticket),
            consoleKind: ConsoleKind::Terminal,
            expiresAt: $grant->expiresAt,
            metadata: [
                'provider' => 'proxmox',
                'node' => $node,
                'vmid' => $vmid,
                'port' => $ticket->port,
                'upid' => $ticket->upid,
                'user' => $ticket->user,
            ],
        );
    }

    private function guard(ConsoleAccessGrant $grant, Deployment $deployment, ConsoleKind $expected): void
    {
        if ($grant->isExpired(CarbonImmutable::now())) {
            $this->audit($grant, $deployment, $expected, 'denied', 'grant_expired', []);

            throw new ProviderConsoleProxyException('Console grant has expired.', reason: 'grant_expired');
        }

        if ($grant->consoleKind !== $expected) {
            $this->audit($grant, $deployment, $expected, 'denied', 'console_kind_mismatch', []);

            throw new ProviderConsoleProxyException(
                sprintf('Console grant kind %s does not match requested %s.', $grant->consoleKind->value, $expected->value),
                reason: 'console_kind_mismatch',
            );
        }

        if ($grant->deploymentId !== $deployment->resourceId()) {
            $this->audit($grant, $deployment, $expected, 'denied', 'deployment_mismatch', []);

            throw new ProviderConsoleProxyException('Console grant deployment does not match.', reason: 'deployment_mismatch');
        }

        if ($grant->tenantId !== $deployment->tenantId()) {
            $this->audit($grant, $deployment, $expected, 'denied', 'tenant_mismatch', []);

            throw new ProviderConsoleProxyException('Console grant tenant does not match.', reason: 'tenant_mismatch');
        }
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function resolveNodeAndVmid(ConsoleAccessGrant $grant, Deployment $deployment): array
    {
        /** @var DeploymentResource|null $resource */
        $resource = DeploymentResource::query()
            ->where('deployment_id', $deployment->getKey())
            ->where('provider', 'proxmox')
            ->where('kind', 'vm')
            ->whereNotIn('state', ['released', 'removed'])
            ->orderBy('component_key')
            ->first();

        if (! $resource instanceof DeploymentResource) {
            $this->audit($grant, $deployment, $grant->consoleKind, 'denied', 'not_a_proxmox_deployment', []);

            throw new ProviderConsoleProxyException(
                'Deployment has no active Proxmox VM resource.',
                reason: 'not_a_proxmox_deployment',
            );
        }

        $metadata = $resource->metadata ?? [];
        $proxmox = is_array($metadata['proxmox'] ?? null) ? $metadata['proxmox'] : [];
        $node = $proxmox['node'] ?? null;

        if (! is_string($node) || trim($node) === '') {
            $this->audit($grant, $deployment, $grant->consoleKind, 'denied', 'missing_node', []);

            throw new ProviderConsoleProxyException(
                'Proxmox resource metadata is missing the node hint.',
                reason: 'missing_node',
            );
        }

        try {
            $vmid = $this->resourceVmid($resource);
        } catch (InvalidArgumentException) {
            $this->audit($grant, $deployment, $grant->consoleKind, 'denied', 'missing_vmid', []);

            throw new ProviderConsoleProxyException(
                'Proxmox resource is missing provider VMID.',
                reason: 'missing_vmid',
            );
        }

        return [$node, $vmid];
    }

    private function resourceVmid(DeploymentResource $resource): int
    {
        if (is_string($resource->provider_resource_id) && ctype_digit($resource->provider_resource_id)) {
            return (int) $resource->provider_resource_id;
        }

        throw new InvalidArgumentException('Proxmox resource is missing provider VMID.');
    }

    private function buildWebsocketUrl(string $node, int $vmid, int $port, string $ticket): string
    {
        // Proxmox's vncwebsocket endpoint requires both port and vncticket. The
        // actual WebSocket bridging through the localhost ProviderConsoleProxy
        // socket is M4 sub-slice 6 work; the future racklab-console-proxy
        // localhost socket dereferences this URL with its own credentials.
        // Nothing inside RackLab exposes this URL to browsers directly.
        return sprintf(
            '/api2/json/nodes/%s/qemu/%d/vncwebsocket?port=%d&vncticket=%s',
            rawurlencode($node),
            $vmid,
            $port,
            rawurlencode($ticket),
        );
    }

    /**
     * @param  array<string, scalar|null>  $extra
     */
    private function audit(
        ConsoleAccessGrant $grant,
        Deployment $deployment,
        ConsoleKind $consoleKind,
        string $result,
        ?string $reason,
        array $extra,
    ): void {
        $metadata = [
            'console_kind' => $consoleKind->value,
            'grant_id' => $grant->grantId,
            'jti' => $grant->jti,
            'provider' => 'proxmox',
        ];

        if ($reason !== null) {
            $metadata['reason'] = $reason;
        }

        foreach ($extra as $key => $value) {
            $metadata[$key] = $value;
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
