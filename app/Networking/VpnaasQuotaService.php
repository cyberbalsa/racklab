<?php

declare(strict_types=1);

namespace App\Networking;

use App\Audit\AuditEventWriter;
use App\Domain\Tenancy\TenantContext;
use App\Models\NetworkVpnEndpoint;
use App\Models\Project;
use App\Models\QuotaEvent;
use App\Models\QuotaLimit;
use App\Models\QuotaUsage;
use App\Models\User;
use App\Models\VpnClientProfile;
use App\Quota\QuotaScopeResolver;
use App\Quota\QuotaUsageCounter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Quota gate for M5c VPNaaS dimensions. Three dimensions land in the M5c
 * sub-slices:
 *
 * - `vpnaas_endpoints` (S2): total deployed endpoints per project scope.
 * - `vpnaas_endpoint_public_ips` / `vpnaas_endpoint_ports` (S3): consumed
 *   per binding when the allocator picks a node-local IP+UDP port.
 * - `vpnaas_client_profiles` (S4): per-user profiles within a project
 *   (group-project Stacks issue one profile per user; this caps the total
 *   active profiles, not a per-user count).
 */
final readonly class VpnaasQuotaService
{
    public const string DIM_ENDPOINTS = 'vpnaas_endpoints';

    public const string DIM_ENDPOINT_PUBLIC_IPS = 'vpnaas_endpoint_public_ips';

    public const string DIM_ENDPOINT_PORTS = 'vpnaas_endpoint_ports';

    public const string DIM_CLIENT_PROFILES = 'vpnaas_client_profiles';

    public function __construct(
        private AuditEventWriter $auditEvents,
        private QuotaScopeResolver $scopes,
        private QuotaUsageCounter $usageCounter,
    ) {}

    /**
     * @return list<QuotaLimit>
     */
    public function assertEndpointAvailable(User $actor, TenantContext $context, Project $project): array
    {
        return $this->assertAvailable(
            actor: $actor,
            context: $context,
            project: $project,
            dimension: self::DIM_ENDPOINTS,
            operationKind: 'network.vpnaas.endpoint.create',
            effectivePermission: 'network.vpnaas.endpoint.create',
        );
    }

    /**
     * @return list<QuotaLimit>
     */
    public function assertEndpointBindingAvailable(User $actor, TenantContext $context, Project $project): array
    {
        $ipLimits = $this->assertAvailable(
            actor: $actor,
            context: $context,
            project: $project,
            dimension: self::DIM_ENDPOINT_PUBLIC_IPS,
            operationKind: 'network.vpnaas.endpoint.binding.create',
            effectivePermission: 'network.vpnaas.endpoint.create',
        );

        $portLimits = $this->assertAvailable(
            actor: $actor,
            context: $context,
            project: $project,
            dimension: self::DIM_ENDPOINT_PORTS,
            operationKind: 'network.vpnaas.endpoint.binding.create',
            effectivePermission: 'network.vpnaas.endpoint.create',
        );

        return [...$ipLimits, ...$portLimits];
    }

    /**
     * @return list<QuotaLimit>
     */
    public function assertClientProfileAvailable(User $actor, TenantContext $context, Project $project): array
    {
        return $this->assertAvailable(
            actor: $actor,
            context: $context,
            project: $project,
            dimension: self::DIM_CLIENT_PROFILES,
            operationKind: 'network.vpnaas.profile.create',
            effectivePermission: 'network.vpnaas.profile.create',
        );
    }

    /**
     * @param  list<QuotaLimit>  $limits
     */
    public function consumeForEndpoint(array $limits, NetworkVpnEndpoint $endpoint, User $actor): void
    {
        $this->consume(
            limits: $limits,
            tenantId: $endpoint->tenant_id,
            projectId: $endpoint->project_id,
            actor: $actor,
            operationKind: 'network.vpnaas.endpoint.create',
            payloadKey: 'network_vpn_endpoint_id',
            payloadValue: $endpoint->resourceId(),
        );
    }

    public function releaseForEndpoint(NetworkVpnEndpoint $endpoint, User $actor): void
    {
        $this->release(
            tenantId: $endpoint->tenant_id,
            projectId: $endpoint->project_id,
            dimension: self::DIM_ENDPOINTS,
            actor: $actor,
            payloadKey: 'network_vpn_endpoint_id',
            payloadValue: $endpoint->resourceId(),
            reason: 'network.vpnaas.endpoint.release',
        );
    }

    /**
     * @param  list<QuotaLimit>  $limits
     */
    public function consumeForBinding(
        array $limits,
        \App\Models\NetworkVpnEndpointBinding $binding,
        NetworkVpnEndpoint $endpoint,
        User $actor,
    ): void {
        foreach ($limits as $limit) {
            $this->consume(
                limits: [$limit],
                tenantId: $endpoint->tenant_id,
                projectId: $endpoint->project_id,
                actor: $actor,
                operationKind: 'network.vpnaas.endpoint.binding.create',
                payloadKey: 'network_vpn_endpoint_binding_id',
                payloadValue: $binding->resourceId(),
            );
        }
    }

    public function releaseForBinding(\App\Models\NetworkVpnEndpointBinding $binding, User $actor): void
    {
        /** @var NetworkVpnEndpoint|null $endpoint */
        $endpoint = NetworkVpnEndpoint::query()->whereKey($binding->network_vpn_endpoint_id)->first();
        $projectId = $endpoint?->project_id;

        $this->release(
            tenantId: $binding->tenant_id,
            projectId: $projectId,
            dimension: self::DIM_ENDPOINT_PUBLIC_IPS,
            actor: $actor,
            payloadKey: 'network_vpn_endpoint_binding_id',
            payloadValue: $binding->resourceId(),
            reason: 'network.vpnaas.endpoint.binding.release',
        );

        $this->release(
            tenantId: $binding->tenant_id,
            projectId: $projectId,
            dimension: self::DIM_ENDPOINT_PORTS,
            actor: $actor,
            payloadKey: 'network_vpn_endpoint_binding_id',
            payloadValue: $binding->resourceId(),
            reason: 'network.vpnaas.endpoint.binding.release',
        );
    }

    /**
     * @param  list<QuotaLimit>  $limits
     */
    public function consumeForProfile(array $limits, VpnClientProfile $profile, User $actor): void
    {
        /** @var NetworkVpnEndpoint|null $endpoint */
        $endpoint = NetworkVpnEndpoint::query()->whereKey($profile->network_vpn_endpoint_id)->first();

        $this->consume(
            limits: $limits,
            tenantId: $profile->tenant_id,
            projectId: $endpoint?->project_id,
            actor: $actor,
            operationKind: 'network.vpnaas.profile.create',
            payloadKey: 'vpn_client_profile_id',
            payloadValue: $profile->resourceId(),
        );
    }

    public function releaseForProfile(VpnClientProfile $profile, User $actor): void
    {
        /** @var NetworkVpnEndpoint|null $endpoint */
        $endpoint = NetworkVpnEndpoint::query()->whereKey($profile->network_vpn_endpoint_id)->first();

        $this->release(
            tenantId: $profile->tenant_id,
            projectId: $endpoint?->project_id,
            dimension: self::DIM_CLIENT_PROFILES,
            actor: $actor,
            payloadKey: 'vpn_client_profile_id',
            payloadValue: $profile->resourceId(),
            reason: 'network.vpnaas.profile.revoke',
        );
    }

    /**
     * @return list<QuotaLimit>
     */
    private function assertAvailable(
        User $actor,
        TenantContext $context,
        Project $project,
        string $dimension,
        string $operationKind,
        string $effectivePermission,
        int $quantity = 1,
    ): array {
        $limits = $this->limitsFor($actor, $context, $project, $dimension);

        foreach ($limits as $limit) {
            $used = $this->usageCounter->usedForLimit($limit);

            if ($used + $quantity > $limit->limit_value) {
                $message = $this->recordDenial(
                    actor: $actor,
                    context: $context,
                    project: $project,
                    limit: $limit,
                    used: $used,
                    quantity: $quantity,
                    operationKind: $operationKind,
                    effectivePermission: $effectivePermission,
                );

                throw ValidationException::withMessages(['quota' => [$message]]);
            }
        }

        return $limits;
    }

    /**
     * @return list<QuotaLimit>
     */
    private function limitsFor(User $actor, TenantContext $context, Project $project, string $dimension): array
    {
        $scopePairs = $this->scopes->scopesFor($actor, $context, $project);

        /** @var list<QuotaLimit> $limits */
        $limits = QuotaLimit::query()
            ->where('tenant_id', $context->activeTenantId)
            ->where('dimension', $dimension)
            ->where(function (Builder $query) use ($scopePairs): void {
                $this->scopes->applyToLimitQuery($query, $scopePairs);
            })
            ->orderBy('scope_type')
            ->orderBy('limit_value')
            ->get()
            ->all();

        return $limits;
    }

    /**
     * @param  list<QuotaLimit>  $limits
     */
    private function consume(
        array $limits,
        string $tenantId,
        ?string $projectId,
        User $actor,
        string $operationKind,
        string $payloadKey,
        string $payloadValue,
    ): void {
        foreach ($limits as $limit) {
            /** @var QuotaUsage $usage */
            $usage = QuotaUsage::query()->create([
                'tenant_id' => $tenantId,
                'quota_limit_id' => $limit->getKey(),
                'quota_reservation_id' => null,
                'project_id' => $projectId,
                'deployment_id' => null,
                'deployment_operation_id' => null,
                'actor_user_id' => $actor->getKey(),
                'scope_type' => $limit->scope_type,
                'scope_id' => $limit->scope_id,
                'dimension' => $limit->dimension,
                'quantity' => 1,
                'state' => 'active',
                'metadata' => [
                    $payloadKey => $payloadValue,
                    'operation_kind' => $operationKind,
                ],
            ]);

            QuotaEvent::query()->create([
                'tenant_id' => $tenantId,
                'event_type' => 'quota.consumed',
                'result' => 'allowed',
                'scope_type' => $usage->scope_type,
                'scope_id' => $usage->scope_id,
                'dimension' => $usage->dimension,
                'quantity' => $usage->quantity,
                'limit_value' => $limit->limit_value,
                'actor_user_id' => $actor->getKey(),
                'project_id' => $projectId,
                'deployment_id' => null,
                'deployment_operation_id' => null,
                'metadata' => [
                    'quota_limit_id' => $limit->getKey(),
                    'quota_usage_id' => $usage->getKey(),
                    $payloadKey => $payloadValue,
                ],
                'created_at' => now(),
            ]);
        }
    }

    private function release(
        string $tenantId,
        ?string $projectId,
        string $dimension,
        User $actor,
        string $payloadKey,
        string $payloadValue,
        string $reason,
    ): void {
        /** @var list<QuotaUsage> $usages */
        $usages = QuotaUsage::query()
            ->where('tenant_id', $tenantId)
            ->where('dimension', $dimension)
            ->where('state', 'active')
            ->lockForUpdate()
            ->get()
            ->all();

        foreach ($usages as $usage) {
            $metadata = $usage->metadata ?? [];

            if (($metadata[$payloadKey] ?? null) !== $payloadValue) {
                continue;
            }

            $usage->forceFill([
                'state' => 'released',
                'metadata' => [
                    ...$metadata,
                    'release_reason' => $reason,
                ],
            ])->save();

            QuotaEvent::query()->create([
                'tenant_id' => $tenantId,
                'event_type' => 'quota.released',
                'result' => 'allowed',
                'scope_type' => $usage->scope_type,
                'scope_id' => $usage->scope_id,
                'dimension' => $usage->dimension,
                'quantity' => $usage->quantity,
                'limit_value' => null,
                'actor_user_id' => $actor->getKey(),
                'project_id' => $projectId,
                'deployment_id' => null,
                'deployment_operation_id' => null,
                'metadata' => [
                    'quota_usage_id' => $usage->getKey(),
                    $payloadKey => $payloadValue,
                    'release_reason' => $reason,
                ],
                'created_at' => now(),
            ]);
        }
    }

    private function recordDenial(
        User $actor,
        TenantContext $context,
        Project $project,
        QuotaLimit $limit,
        int $used,
        int $quantity,
        string $operationKind,
        string $effectivePermission,
    ): string {
        $available = max(0, $limit->limit_value - $used);
        $message = sprintf(
            'VPNaaS quota exceeded: %s limit %d, used %d, requested %d.',
            $limit->dimension,
            $limit->limit_value,
            $used,
            $quantity,
        );

        QuotaEvent::query()->create([
            'tenant_id' => $context->activeTenantId,
            'event_type' => 'quota.denied',
            'result' => 'denied',
            'scope_type' => $limit->scope_type,
            'scope_id' => $limit->scope_id,
            'dimension' => $limit->dimension,
            'quantity' => $quantity,
            'limit_value' => $limit->limit_value,
            'actor_user_id' => $actor->getKey(),
            'project_id' => $project->getKey(),
            'deployment_id' => null,
            'deployment_operation_id' => null,
            'metadata' => [
                'quota_limit_id' => $limit->getKey(),
                'operation_kind' => $operationKind,
                'available' => $available,
                'used' => $used,
            ],
            'created_at' => now(),
        ]);

        $this->auditEvents->append([
            'event_type' => 'quota.denied',
            'action' => $operationKind,
            'result' => 'denied',
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => 'project',
            'resource_id' => $project->getKey(),
            'resource_tenant' => $project->tenant_id,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => [$effectivePermission],
            'metadata' => [
                'dimension' => $limit->dimension,
                'limit_value' => $limit->limit_value,
                'used' => $used,
                'requested' => $quantity,
                'operation_kind' => $operationKind,
            ],
        ]);

        return $message;
    }
}
