<?php

declare(strict_types=1);

namespace App\Networking;

use App\Audit\AuditEventWriter;
use App\Domain\Tenancy\TenantContext;
use App\Models\FloatingIp;
use App\Models\Network;
use App\Models\Project;
use App\Models\QuotaEvent;
use App\Models\QuotaLimit;
use App\Models\QuotaUsage;
use App\Models\Router;
use App\Models\SecurityGroup;
use App\Models\User;
use App\Quota\QuotaScopeResolver;
use App\Quota\QuotaUsageCounter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final readonly class NetworkQuotaService
{
    private const string PRIVATE_NETWORKS = 'private_networks';

    public function __construct(
        private AuditEventWriter $auditEvents,
        private QuotaScopeResolver $scopes,
        private QuotaUsageCounter $usageCounter,
    ) {}

    /**
     * @return list<QuotaLimit>
     */
    public function assertPrivateNetworkAvailable(User $actor, TenantContext $context, Project $project): array
    {
        return $this->assertAvailable(
            actor: $actor,
            context: $context,
            project: $project,
            dimension: self::PRIVATE_NETWORKS,
            operationKind: 'network.create',
            effectivePermission: 'network.create_private',
        );
    }

    /**
     * @return list<QuotaLimit>
     */
    public function assertRouterAvailable(User $actor, TenantContext $context, Project $project): array
    {
        return $this->assertAvailable(
            actor: $actor,
            context: $context,
            project: $project,
            dimension: 'routers',
            operationKind: 'network.router.create',
            effectivePermission: 'network.create_router',
        );
    }

    /**
     * @return list<QuotaLimit>
     */
    public function assertFloatingIpAvailable(User $actor, TenantContext $context, Project $project): array
    {
        return $this->assertAvailable(
            actor: $actor,
            context: $context,
            project: $project,
            dimension: 'floating_ips',
            operationKind: 'network.floating_ip.allocate',
            effectivePermission: 'network.allocate_public_ip',
        );
    }

    /**
     * @return list<QuotaLimit>
     */
    public function assertSecurityGroupRuleCapacity(
        User $actor,
        TenantContext $context,
        Project $project,
        int $ruleCount,
        string $operationKind,
        int $existingQuantity = 0,
    ): array {
        return $this->assertAvailable(
            actor: $actor,
            context: $context,
            project: $project,
            dimension: 'security_group_rules',
            operationKind: $operationKind,
            effectivePermission: 'network.manage_security_group',
            quantity: $ruleCount,
            existingQuantity: $existingQuantity,
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
        int $existingQuantity = 0,
    ): array {
        $limits = $this->limitsFor($actor, $context, $project, $dimension);

        foreach ($limits as $limit) {
            $used = $this->usageCounter->usedForLimit($limit);
            $effectiveUsed = max(0, $used - $existingQuantity);

            if ($effectiveUsed + $quantity > $limit->limit_value) {
                $message = $this->recordDenial(
                    actor: $actor,
                    context: $context,
                    project: $project,
                    limit: $limit,
                    used: $effectiveUsed,
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
     * @param  list<QuotaLimit>  $limits
     */
    public function consumeForNetwork(array $limits, Network $network, User $actor): void
    {
        foreach ($limits as $limit) {
            /** @var QuotaUsage $usage */
            $usage = QuotaUsage::query()->create([
                'tenant_id' => $network->tenant_id,
                'quota_limit_id' => $limit->getKey(),
                'quota_reservation_id' => null,
                'project_id' => $network->project_id,
                'deployment_id' => null,
                'deployment_operation_id' => null,
                'actor_user_id' => $actor->getKey(),
                'scope_type' => $limit->scope_type,
                'scope_id' => $limit->scope_id,
                'dimension' => $limit->dimension,
                'quantity' => 1,
                'state' => 'active',
                'metadata' => [
                    'network_id' => $network->getKey(),
                    'operation_kind' => 'network.create',
                ],
            ]);

            QuotaEvent::query()->create([
                'tenant_id' => $network->tenant_id,
                'event_type' => 'quota.consumed',
                'result' => 'allowed',
                'scope_type' => $usage->scope_type,
                'scope_id' => $usage->scope_id,
                'dimension' => $usage->dimension,
                'quantity' => $usage->quantity,
                'limit_value' => $limit->limit_value,
                'actor_user_id' => $actor->getKey(),
                'project_id' => $network->project_id,
                'deployment_id' => null,
                'deployment_operation_id' => null,
                'metadata' => [
                    'quota_limit_id' => $limit->getKey(),
                    'quota_usage_id' => $usage->getKey(),
                    'network_id' => $network->getKey(),
                ],
                'created_at' => now(),
            ]);
        }
    }

    /**
     * @param  list<QuotaLimit>  $limits
     */
    public function consumeForRouter(array $limits, Router $router, User $actor): void
    {
        foreach ($limits as $limit) {
            /** @var QuotaUsage $usage */
            $usage = QuotaUsage::query()->create([
                'tenant_id' => $router->tenant_id,
                'quota_limit_id' => $limit->getKey(),
                'quota_reservation_id' => null,
                'project_id' => $router->project_id,
                'deployment_id' => null,
                'deployment_operation_id' => null,
                'actor_user_id' => $actor->getKey(),
                'scope_type' => $limit->scope_type,
                'scope_id' => $limit->scope_id,
                'dimension' => $limit->dimension,
                'quantity' => 1,
                'state' => 'active',
                'metadata' => [
                    'router_id' => $router->getKey(),
                    'operation_kind' => 'network.router.create',
                ],
            ]);

            QuotaEvent::query()->create([
                'tenant_id' => $router->tenant_id,
                'event_type' => 'quota.consumed',
                'result' => 'allowed',
                'scope_type' => $usage->scope_type,
                'scope_id' => $usage->scope_id,
                'dimension' => $usage->dimension,
                'quantity' => $usage->quantity,
                'limit_value' => $limit->limit_value,
                'actor_user_id' => $actor->getKey(),
                'project_id' => $router->project_id,
                'deployment_id' => null,
                'deployment_operation_id' => null,
                'metadata' => [
                    'quota_limit_id' => $limit->getKey(),
                    'quota_usage_id' => $usage->getKey(),
                    'router_id' => $router->getKey(),
                ],
                'created_at' => now(),
            ]);
        }
    }

    /**
     * @param  list<QuotaLimit>  $limits
     */
    public function consumeForFloatingIp(array $limits, FloatingIp $floatingIp, User $actor): void
    {
        foreach ($limits as $limit) {
            /** @var QuotaUsage $usage */
            $usage = QuotaUsage::query()->create([
                'tenant_id' => $floatingIp->tenant_id,
                'quota_limit_id' => $limit->getKey(),
                'quota_reservation_id' => null,
                'project_id' => $floatingIp->project_id,
                'deployment_id' => null,
                'deployment_operation_id' => null,
                'actor_user_id' => $actor->getKey(),
                'scope_type' => $limit->scope_type,
                'scope_id' => $limit->scope_id,
                'dimension' => $limit->dimension,
                'quantity' => 1,
                'state' => 'active',
                'metadata' => [
                    'floating_ip_id' => $floatingIp->getKey(),
                    'operation_kind' => 'network.floating_ip.allocate',
                ],
            ]);

            QuotaEvent::query()->create([
                'tenant_id' => $floatingIp->tenant_id,
                'event_type' => 'quota.consumed',
                'result' => 'allowed',
                'scope_type' => $usage->scope_type,
                'scope_id' => $usage->scope_id,
                'dimension' => $usage->dimension,
                'quantity' => $usage->quantity,
                'limit_value' => $limit->limit_value,
                'actor_user_id' => $actor->getKey(),
                'project_id' => $floatingIp->project_id,
                'deployment_id' => null,
                'deployment_operation_id' => null,
                'metadata' => [
                    'quota_limit_id' => $limit->getKey(),
                    'quota_usage_id' => $usage->getKey(),
                    'floating_ip_id' => $floatingIp->getKey(),
                ],
                'created_at' => now(),
            ]);
        }
    }

    public function releaseForFloatingIp(FloatingIp $floatingIp, User $actor): void
    {
        /** @var list<QuotaUsage> $usages */
        $usages = QuotaUsage::query()
            ->where('tenant_id', $floatingIp->tenant_id)
            ->where('project_id', $floatingIp->project_id)
            ->where('dimension', 'floating_ips')
            ->where('state', 'active')
            ->lockForUpdate()
            ->get()
            ->all();

        foreach ($usages as $usage) {
            $metadata = $usage->metadata ?? [];

            if (($metadata['floating_ip_id'] ?? null) !== $floatingIp->getKey()) {
                continue;
            }

            $usage->forceFill([
                'state' => 'released',
                'metadata' => [
                    ...$metadata,
                    'release_reason' => 'floating_ip.release',
                    'released_at' => now()->toJSON(),
                ],
            ])->save();

            $limit = $usage->quota_limit_id !== null
                ? QuotaLimit::query()->whereKey($usage->quota_limit_id)->first()
                : null;

            QuotaEvent::query()->create([
                'tenant_id' => $floatingIp->tenant_id,
                'event_type' => 'quota.released',
                'result' => 'allowed',
                'scope_type' => $usage->scope_type,
                'scope_id' => $usage->scope_id,
                'dimension' => $usage->dimension,
                'quantity' => $usage->quantity,
                'limit_value' => $limit instanceof QuotaLimit ? $limit->limit_value : null,
                'actor_user_id' => $actor->getKey(),
                'project_id' => $floatingIp->project_id,
                'deployment_id' => null,
                'deployment_operation_id' => null,
                'metadata' => [
                    'quota_usage_id' => $usage->getKey(),
                    'floating_ip_id' => $floatingIp->getKey(),
                    'operation_kind' => 'network.floating_ip.release',
                ],
                'created_at' => now(),
            ]);
        }
    }

    /**
     * @param  list<QuotaLimit>  $limits
     */
    public function replaceSecurityGroupRuleUsage(
        array $limits,
        SecurityGroup $securityGroup,
        User $actor,
        int $ruleCount,
        string $operationKind,
    ): void {
        foreach ($limits as $limit) {
            $usage = $this->activeSecurityGroupUsage($securityGroup, $limit);

            if (! $usage instanceof QuotaUsage) {
                /** @var QuotaUsage $usage */
                $usage = QuotaUsage::query()->create([
                    'tenant_id' => $securityGroup->tenant_id,
                    'quota_limit_id' => $limit->getKey(),
                    'quota_reservation_id' => null,
                    'project_id' => $securityGroup->project_id,
                    'deployment_id' => null,
                    'deployment_operation_id' => null,
                    'actor_user_id' => $actor->getKey(),
                    'scope_type' => $limit->scope_type,
                    'scope_id' => $limit->scope_id,
                    'dimension' => $limit->dimension,
                    'quantity' => $ruleCount,
                    'state' => 'active',
                    'metadata' => [
                        'security_group_id' => $securityGroup->getKey(),
                        'operation_kind' => $operationKind,
                    ],
                ]);
            } else {
                $usage->forceFill([
                    'quantity' => $ruleCount,
                    'metadata' => [
                        ...($usage->metadata ?? []),
                        'operation_kind' => $operationKind,
                        'updated_at' => now()->toJSON(),
                    ],
                ])->save();
            }

            QuotaEvent::query()->create([
                'tenant_id' => $securityGroup->tenant_id,
                'event_type' => 'quota.consumed',
                'result' => 'allowed',
                'scope_type' => $usage->scope_type,
                'scope_id' => $usage->scope_id,
                'dimension' => $usage->dimension,
                'quantity' => $ruleCount,
                'limit_value' => $limit->limit_value,
                'actor_user_id' => $actor->getKey(),
                'project_id' => $securityGroup->project_id,
                'deployment_id' => null,
                'deployment_operation_id' => null,
                'metadata' => [
                    'quota_limit_id' => $limit->getKey(),
                    'quota_usage_id' => $usage->getKey(),
                    'security_group_id' => $securityGroup->getKey(),
                    'operation_kind' => $operationKind,
                ],
                'created_at' => now(),
            ]);
        }
    }

    public function activeSecurityGroupRuleQuantity(SecurityGroup $securityGroup): int
    {
        /** @var list<QuotaUsage> $usages */
        $usages = QuotaUsage::query()
            ->where('tenant_id', $securityGroup->tenant_id)
            ->where('project_id', $securityGroup->project_id)
            ->where('dimension', 'security_group_rules')
            ->where('state', 'active')
            ->get()
            ->all();

        foreach ($usages as $usage) {
            $metadata = $usage->metadata ?? [];

            if (($metadata['security_group_id'] ?? null) === $securityGroup->getKey()) {
                return $usage->quantity;
            }
        }

        return 0;
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
            ->orderBy('scope_id')
            ->get()
            ->all();

        return $limits;
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
        $metadata = [
            'dimension' => $limit->dimension,
            'requested' => $quantity,
            'used' => $used,
            'available' => $available,
            'limit_value' => $limit->limit_value,
            'scope_type' => $limit->scope_type,
            'scope_id' => $limit->scope_id,
            'operation_kind' => $operationKind,
            'project_id' => $project->getKey(),
        ];

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
            'metadata' => $metadata,
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
            'metadata' => $metadata,
        ]);

        return sprintf(
            'Quota %s exceeded for %s scope: requested %d, available %d, limit %d.',
            $limit->dimension,
            $limit->scope_type,
            $quantity,
            $available,
            $limit->limit_value,
        );
    }

    private function activeSecurityGroupUsage(SecurityGroup $securityGroup, QuotaLimit $limit): ?QuotaUsage
    {
        /** @var list<QuotaUsage> $usages */
        $usages = QuotaUsage::query()
            ->where('tenant_id', $securityGroup->tenant_id)
            ->where('quota_limit_id', $limit->getKey())
            ->where('dimension', 'security_group_rules')
            ->where('state', 'active')
            ->lockForUpdate()
            ->get()
            ->all();

        foreach ($usages as $usage) {
            $metadata = $usage->metadata ?? [];

            if (($metadata['security_group_id'] ?? null) === $securityGroup->getKey()) {
                return $usage;
            }
        }

        return null;
    }
}
