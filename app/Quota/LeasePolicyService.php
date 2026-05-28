<?php

declare(strict_types=1);

namespace App\Quota;

use App\Audit\AuditEventWriter;
use App\Domain\Tenancy\TenantContext;
use App\Models\Project;
use App\Models\QuotaEvent;
use App\Models\QuotaLimit;
use App\Models\StackDefinition;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final readonly class LeasePolicyService
{
    private const string DURATION_DIMENSION = 'lease_duration_minutes';

    private const string CONCURRENT_DIMENSION = 'concurrent_leased_deployments';

    public function __construct(
        private AuditEventWriter $auditEvents,
        private QuotaScopeResolver $scopes,
    ) {}

    public function forDeploymentCreate(
        User $actor,
        TenantContext $context,
        Project $project,
        StackDefinition $stack,
        string $operationKind,
        bool $newDeployment,
        ?int $requestedDurationMinutes,
    ): LeasePolicyDecision {
        if (! $newDeployment) {
            return new LeasePolicyDecision(null, null, [], []);
        }

        $durationLimit = $this->mostRestrictiveLimit($actor, $context, $project, self::DURATION_DIMENSION);

        if ($requestedDurationMinutes !== null && $durationLimit instanceof QuotaLimit && $requestedDurationMinutes > $durationLimit->limit_value) {
            $message = $this->recordDurationDenial(
                actor: $actor,
                context: $context,
                project: $project,
                stack: $stack,
                limit: $durationLimit,
                operationKind: $operationKind,
                requestedDurationMinutes: $requestedDurationMinutes,
            );

            throw ValidationException::withMessages(['lease_duration_minutes' => [$message]]);
        }

        if ($requestedDurationMinutes === null && $durationLimit instanceof QuotaLimit && $durationLimit->limit_value <= 0) {
            $message = $this->recordDurationDenial(
                actor: $actor,
                context: $context,
                project: $project,
                stack: $stack,
                limit: $durationLimit,
                operationKind: $operationKind,
                requestedDurationMinutes: 0,
            );

            throw ValidationException::withMessages(['lease_duration_minutes' => [$message]]);
        }

        $durationMinutes = $requestedDurationMinutes;

        if ($durationMinutes === null && $durationLimit instanceof QuotaLimit) {
            $durationMinutes = $durationLimit->limit_value;
        }

        if ($durationMinutes === null) {
            return new LeasePolicyDecision(null, null, [], []);
        }

        $expiresAt = now()->toImmutable()->addMinutes($durationMinutes);
        $metadata = [
            'duration_minutes' => $durationMinutes,
            'expires_at' => $expiresAt->toJSON(),
            'requested_duration_minutes' => $requestedDurationMinutes,
        ];

        if ($durationLimit instanceof QuotaLimit) {
            $metadata['quota_limit_id'] = $durationLimit->getKey();
            $metadata['scope_type'] = $durationLimit->scope_type;
            $metadata['scope_id'] = $durationLimit->scope_id;
            $metadata['limit_value'] = $durationLimit->limit_value;
        }

        return new LeasePolicyDecision(
            expiresAt: $expiresAt,
            durationMinutes: $durationMinutes,
            quotaRequirements: [self::CONCURRENT_DIMENSION => 1],
            metadata: $metadata,
        );
    }

    private function mostRestrictiveLimit(User $actor, TenantContext $context, Project $project, string $dimension): ?QuotaLimit
    {
        $scopePairs = $this->scopes->scopesFor($actor, $context, $project);

        /** @var list<QuotaLimit> $limits */
        $limits = QuotaLimit::query()
            ->where('tenant_id', $context->activeTenantId)
            ->where('dimension', $dimension)
            ->where(function (Builder $query) use ($scopePairs): void {
                $this->scopes->applyToLimitQuery($query, $scopePairs);
            })
            ->orderBy('limit_value')
            ->orderBy('scope_type')
            ->orderBy('scope_id')
            ->get()
            ->all();

        return $limits[0] ?? null;
    }

    private function recordDurationDenial(
        User $actor,
        TenantContext $context,
        Project $project,
        StackDefinition $stack,
        QuotaLimit $limit,
        string $operationKind,
        int $requestedDurationMinutes,
    ): string {
        $metadata = [
            'dimension' => $limit->dimension,
            'requested' => $requestedDurationMinutes,
            'available' => $limit->limit_value,
            'limit_value' => $limit->limit_value,
            'scope_type' => $limit->scope_type,
            'scope_id' => $limit->scope_id,
            'operation_kind' => $operationKind,
            'new_deployment' => true,
            'project_id' => $project->getKey(),
            'stack_definition_id' => $stack->getKey(),
        ];

        QuotaEvent::query()->create([
            'tenant_id' => $context->activeTenantId,
            'event_type' => 'quota.denied',
            'result' => 'denied',
            'scope_type' => $limit->scope_type,
            'scope_id' => $limit->scope_id,
            'dimension' => $limit->dimension,
            'quantity' => $requestedDurationMinutes,
            'limit_value' => $limit->limit_value,
            'actor_user_id' => $actor->getKey(),
            'project_id' => $project->getKey(),
            'metadata' => $metadata,
            'created_at' => now(),
        ]);

        $this->auditEvents->append([
            'event_type' => 'quota.denied',
            'action' => 'lease_duration',
            'result' => 'denied',
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => 'project',
            'resource_id' => $project->getKey(),
            'resource_tenant' => $project->tenant_id,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => ['deployment.create'],
            'metadata' => $metadata,
        ]);

        return sprintf(
            'Lease duration exceeds %s scope policy: requested %d minutes, maximum %d minutes.',
            $limit->scope_type,
            $requestedDurationMinutes,
            $limit->limit_value,
        );
    }
}
