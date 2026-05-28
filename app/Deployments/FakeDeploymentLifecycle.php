<?php

declare(strict_types=1);

namespace App\Deployments;

use App\Audit\AuditEventWriter;
use App\Broadcasting\BroadcastEventLogWriter;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\RunFakeProviderTask;
use App\Models\Deployment;
use App\Models\DeploymentOperation;
use App\Models\DeploymentResource;
use App\Models\DeploymentStateTransition;
use App\Models\Project;
use App\Models\ProjectDefaultStack;
use App\Models\ProviderTask;
use App\Models\RoleBinding;
use App\Models\StackDefinition;
use App\Models\User;
use App\Networking\NetworkAttachmentValidator;
use App\Quota\LeasePolicyDecision;
use App\Quota\LeasePolicyService;
use App\Quota\QuotaReservationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final readonly class FakeDeploymentLifecycle
{
    public function __construct(
        private AccessResolver $accessResolver,
        private AuditEventWriter $auditEvents,
        private BroadcastEventLogWriter $broadcastEvents,
        private QuotaReservationService $quota,
        private LeasePolicyService $leasePolicies,
        private NetworkAttachmentValidator $networkValidator,
    ) {}

    public function request(
        User $actor,
        TenantContext $context,
        Project $project,
        StackDefinition $stack,
        string $operationKind,
        string $idempotencyKey,
        Request $request,
        bool $simulateFailure = false,
    ): DeploymentCreateResult {
        $operationKind = $this->normalizeOperation($operationKind);
        $actorIdentity = new ActorIdentity((string) $actor->id);
        $decision = $this->accessResolver->permitted(
            $actorIdentity,
            new Permission('deployment.create'),
            $project,
            $context,
        );

        if (! $decision->allowed) {
            $this->auditDenied($actor, $context, $project, $operationKind, $request);

            throw new AuthorizationException('You are not allowed to create deployments for this project.');
        }

        /** @var DeploymentOperation|null $existingOperation */
        $existingOperation = DeploymentOperation::query()
            ->where('actor_user_id', $actor->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingOperation instanceof DeploymentOperation) {
            /** @var Deployment $deployment */
            $deployment = Deployment::query()->whereKey($existingOperation->deployment_id)->firstOrFail();

            return new DeploymentCreateResult($deployment, $existingOperation, idempotentReplay: true);
        }

        $this->networkValidator->validateStackForProvider(
            actor: $actor,
            context: $context,
            project: $project,
            stack: $stack,
            provider: 'fake',
            request: $request,
            effectivePermission: 'deployment.create',
        );

        $newDeployment = $this->willCreateDeployment($project, $operationKind);
        $lease = $this->leasePolicies->forDeploymentCreate(
            actor: $actor,
            context: $context,
            project: $project,
            stack: $stack,
            operationKind: $operationKind,
            newDeployment: $newDeployment,
            requestedDurationMinutes: $this->leaseDurationMinutes($request),
        );
        $reservations = $this->quota->reserveDeploymentCreate(
            actor: $actor,
            context: $context,
            project: $project,
            stack: $stack,
            operationKind: $operationKind,
            newDeployment: $newDeployment,
            extraRequirements: $lease->quotaRequirements,
        );

        try {
            $pending = DB::transaction(function () use (
                $actor,
                $context,
                $project,
                $stack,
                $operationKind,
                $idempotencyKey,
                $request,
                $simulateFailure,
                $reservations,
                $lease,
            ): PendingProviderTask {
                $deployment = $this->deploymentForOperation($actor, $context, $project, $stack, $operationKind, $lease);
                $componentKey = $this->nextComponentKey($deployment);

                /** @var DeploymentOperation $operation */
                $operation = DeploymentOperation::query()->create([
                    'tenant_id' => $context->activeTenantId,
                    'deployment_id' => $deployment->getKey(),
                    'project_id' => $project->getKey(),
                    'stack_definition_id' => $stack->getKey(),
                    'actor_user_id' => $actor->getKey(),
                    'kind' => $operationKind,
                    'idempotency_key' => $idempotencyKey,
                    'state' => 'pending',
                    'requested_diff' => [
                        'component_key' => $componentKey,
                        'provider' => 'fake',
                    ],
                ]);
                $this->quota->attachReservations($reservations, $deployment, $operation);

                $this->auditEvents->append([
                    'event_type' => 'deployment.request',
                    'action' => $operationKind,
                    'result' => 'allowed',
                    'actor_type' => 'user',
                    'actor_id' => (string) $actor->id,
                    'actor_tenant' => $context->activeTenantId,
                    'resource_type' => 'deployment',
                    'resource_id' => $deployment->getKey(),
                    'resource_tenant' => $context->activeTenantId,
                    'target_tenant_set' => [$context->activeTenantId],
                    'effective_permissions' => ['deployment.create'],
                    'source_ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'metadata' => [
                        'operation_id' => $operation->getKey(),
                        'project_id' => $project->getKey(),
                        'stack_definition_id' => $stack->getKey(),
                        'provider' => 'fake',
                        ...($lease->metadata === [] ? [] : ['lease' => $lease->metadata]),
                    ],
                ]);
                $channel = $this->deploymentChannel($context->activeTenantId, $deployment->id);
                $this->broadcastEvents->append(
                    tenantId: $context->activeTenantId,
                    channel: $channel,
                    eventClass: 'App\\Events\\Deployments\\DeploymentRequested',
                    payload: [
                        'deployment_id' => $deployment->getKey(),
                        'operation_id' => $operation->getKey(),
                        'state' => $deployment->state,
                        'operation' => $operationKind,
                        'provider' => 'fake',
                    ],
                );

                $providerTaskId = 'fake-task-'.$operation->id;
                $task = $this->recordProviderTask(
                    context: $context,
                    deployment: $deployment,
                    operation: $operation,
                    action: $operationKind,
                    state: 'pending',
                    providerTaskId: $providerTaskId,
                    errorMessage: null,
                    metadata: [
                        'component_key' => $componentKey,
                        'simulate_failure' => $simulateFailure,
                        'source_ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ],
                );

                return new PendingProviderTask($deployment, $operation, $task);
            });
        } catch (Throwable $throwable) {
            $this->quota->releaseReservations($reservations, 'deployment_request_failed');

            throw $throwable;
        }

        RunFakeProviderTask::dispatch($context->activeTenantId, $pending->task->id);

        return new DeploymentCreateResult($pending->deployment->refresh(), $pending->operation->refresh(), idempotentReplay: false);
    }

    public function operate(
        User $actor,
        TenantContext $context,
        Deployment $deployment,
        string $operationKind,
        ?string $deploymentResourceId,
        string $idempotencyKey,
        Request $request,
        bool $simulateFailure = false,
    ): DeploymentCreateResult {
        $operationKind = $this->normalizeDeploymentOperation($operationKind);
        $decision = $this->accessResolver->permitted(
            new ActorIdentity((string) $actor->id),
            new Permission('deployment.update'),
            $deployment,
            $context,
        );

        if (! $decision->allowed) {
            throw new AuthorizationException('You are not allowed to update this deployment.');
        }

        /** @var DeploymentOperation|null $existingOperation */
        $existingOperation = DeploymentOperation::query()
            ->where('actor_user_id', $actor->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingOperation instanceof DeploymentOperation) {
            /** @var Deployment $existingDeployment */
            $existingDeployment = Deployment::query()->whereKey($existingOperation->deployment_id)->firstOrFail();

            return new DeploymentCreateResult($existingDeployment, $existingOperation, idempotentReplay: true);
        }

        /** @var Project $project */
        $project = Project::query()->whereKey($deployment->project_id)->firstOrFail();
        /** @var StackDefinition $stack */
        $stack = StackDefinition::query()->whereKey($deployment->stack_definition_id)->firstOrFail();

        if ($operationKind === 'add_vm') {
            $this->networkValidator->validateStackForProvider(
                actor: $actor,
                context: $context,
                project: $project,
                stack: $stack,
                provider: 'fake',
                request: $request,
                effectivePermission: 'deployment.update',
            );
        }

        $reservations = $operationKind === 'add_vm'
            ? $this->quota->reserveDeploymentCreate(
                actor: $actor,
                context: $context,
                project: $project,
                stack: $stack,
                operationKind: $operationKind,
                newDeployment: false,
            )
            : [];

        try {
            $pending = DB::transaction(function () use (
                $actor,
                $context,
                $deployment,
                $operationKind,
                $deploymentResourceId,
                $idempotencyKey,
                $request,
                $simulateFailure,
                $project,
                $stack,
                $reservations,
            ): PendingProviderTask {
                $componentKey = $operationKind === 'add_vm' ? $this->nextComponentKey($deployment) : null;
                /** @var DeploymentOperation $operation */
                $operation = DeploymentOperation::query()->create([
                    'tenant_id' => $context->activeTenantId,
                    'deployment_id' => $deployment->getKey(),
                    'project_id' => $project->getKey(),
                    'stack_definition_id' => $stack->getKey(),
                    'actor_user_id' => $actor->getKey(),
                    'kind' => $operationKind,
                    'idempotency_key' => $idempotencyKey,
                    'state' => 'pending',
                    'requested_diff' => [
                        'deployment_resource_id' => $deploymentResourceId,
                        'component_key' => $componentKey,
                        'provider' => 'fake',
                    ],
                ]);
                $this->quota->attachReservations($reservations, $deployment, $operation);

                $this->auditEvents->append([
                    'event_type' => 'deployment.request',
                    'action' => $operationKind,
                    'result' => 'allowed',
                    'actor_type' => 'user',
                    'actor_id' => (string) $actor->id,
                    'actor_tenant' => $context->activeTenantId,
                    'resource_type' => 'deployment',
                    'resource_id' => $deployment->getKey(),
                    'resource_tenant' => $context->activeTenantId,
                    'target_tenant_set' => [$context->activeTenantId],
                    'effective_permissions' => ['deployment.update'],
                    'source_ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'metadata' => [
                        'operation_id' => $operation->getKey(),
                        'provider' => 'fake',
                    ],
                ]);

                $providerTask = $this->recordProviderTask(
                    context: $context,
                    deployment: $deployment,
                    operation: $operation,
                    action: $operationKind,
                    state: 'pending',
                    providerTaskId: 'fake-task-'.$operation->id,
                    errorMessage: null,
                    metadata: [
                        'deployment_resource_id' => $deploymentResourceId,
                        'component_key' => $componentKey,
                        'simulate_failure' => $simulateFailure,
                        'source_ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ],
                );

                return new PendingProviderTask($deployment, $operation, $providerTask);
            });
        } catch (Throwable $throwable) {
            $this->quota->releaseReservations($reservations, 'deployment_operation_request_failed');

            throw $throwable;
        }

        RunFakeProviderTask::dispatch($context->activeTenantId, $pending->task->id);

        return new DeploymentCreateResult($pending->deployment->refresh(), $pending->operation->refresh(), idempotentReplay: false);
    }

    private function deploymentForOperation(
        User $actor,
        TenantContext $context,
        Project $project,
        StackDefinition $stack,
        string $operationKind,
        LeasePolicyDecision $lease,
    ): Deployment {
        if ($operationKind === 'add_vm') {
            /** @var ProjectDefaultStack|null $pointer */
            $pointer = ProjectDefaultStack::query()
                ->where('project_id', $project->getKey())
                ->lockForUpdate()
                ->first();

            if (! $pointer instanceof ProjectDefaultStack) {
                throw new RuntimeException('Project default stack pointer not found.');
            }

            if ($pointer->active_deployment_id !== null) {
                /** @var Deployment|null $activeDeployment */
                $activeDeployment = Deployment::query()->whereKey($pointer->active_deployment_id)->first();

                if ($activeDeployment instanceof Deployment) {
                    return $activeDeployment;
                }
            }

            $deployment = $this->createDeployment($actor, $context, $project, $stack, $lease);
            $pointer->forceFill(['active_deployment_id' => $deployment->getKey()])->save();

            return $deployment;
        }

        return $this->createDeployment($actor, $context, $project, $stack, $lease);
    }

    private function willCreateDeployment(Project $project, string $operationKind): bool
    {
        if ($operationKind !== 'add_vm') {
            return true;
        }

        /** @var ProjectDefaultStack|null $pointer */
        $pointer = ProjectDefaultStack::query()
            ->where('project_id', $project->getKey())
            ->first();

        if (! $pointer instanceof ProjectDefaultStack || $pointer->active_deployment_id === null) {
            return true;
        }

        return ! Deployment::query()->whereKey($pointer->active_deployment_id)->exists();
    }

    private function createDeployment(
        User $actor,
        TenantContext $context,
        Project $project,
        StackDefinition $stack,
        LeasePolicyDecision $lease,
    ): Deployment {
        /** @var Deployment $deployment */
        $deployment = Deployment::query()->create([
            'tenant_id' => $context->activeTenantId,
            'project_id' => $project->getKey(),
            'stack_definition_id' => $stack->getKey(),
            'requested_by_id' => $actor->getKey(),
            'name' => $stack->name,
            'state' => 'pending',
            'provider' => 'fake',
            'lease_expires_at' => $lease->expiresAt,
            'metadata' => [
                'source' => 'fake_provider',
                ...($lease->metadata === [] ? [] : ['lease' => $lease->metadata]),
            ],
            'sharing_scope' => 'tenant_local',
            'shared_with_tenants' => [],
        ]);

        RoleBinding::query()->create([
            'principal_type' => 'user',
            'principal_id' => (string) $actor->id,
            'role' => 'student',
            'resource_type' => $deployment->resourceType(),
            'resource_id' => $deployment->resourceId(),
            'scope_type' => RoleBindingScopeType::TenantLocal,
            'tenant_id' => $context->activeTenantId,
            'tenant_set' => [$context->activeTenantId],
            'granted_by_id' => $actor->getKey(),
            'granted_reason' => 'deployment requester access',
        ]);

        DeploymentStateTransition::query()->create([
            'tenant_id' => $context->activeTenantId,
            'deployment_id' => $deployment->getKey(),
            'from_state' => null,
            'to_state' => 'pending',
            'reason' => 'request_created',
            'metadata' => [
                'provider' => 'fake',
            ],
        ]);

        return $deployment;
    }

    private function nextComponentKey(Deployment $deployment): string
    {
        return sprintf('vm-%d', DeploymentResource::query()
            ->where('deployment_id', $deployment->getKey())
            ->count() + 1);
    }

    private function leaseDurationMinutes(Request $request): ?int
    {
        $value = $request->input('lease_duration_minutes');

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private function normalizeOperation(string $operationKind): string
    {
        return match ($operationKind) {
            'deploy', 'add_vm' => $operationKind,
            default => 'deploy',
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordProviderTask(
        TenantContext $context,
        Deployment $deployment,
        DeploymentOperation $operation,
        string $action,
        string $state,
        string $providerTaskId,
        ?string $errorMessage,
        array $metadata,
    ): ProviderTask {
        /** @var ProviderTask $task */
        $task = ProviderTask::query()->create([
            'tenant_id' => $context->activeTenantId,
            'deployment_id' => $deployment->getKey(),
            'deployment_operation_id' => $operation->getKey(),
            'provider' => 'fake',
            'provider_task_id' => $providerTaskId,
            'action' => $action,
            'state' => $state,
            'attempts' => 1,
            'error_message' => $errorMessage,
            'metadata' => [
                ...$metadata,
                'deployment_state' => $deployment->state,
            ],
        ]);

        return $task;
    }

    private function normalizeDeploymentOperation(string $operationKind): string
    {
        return match ($operationKind) {
            'add_vm', 'remove_vm', 'rebuild_vm', 'rebuild_stack', 'release' => $operationKind,
            default => 'rebuild_stack',
        };
    }

    private function auditDenied(
        User $actor,
        TenantContext $context,
        Project $project,
        string $operationKind,
        Request $request,
    ): void {
        $this->auditEvents->append([
            'event_type' => 'deployment.request',
            'action' => $operationKind,
            'result' => 'denied',
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => 'project',
            'resource_id' => $project->getKey(),
            'resource_tenant' => $project->tenant_id,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => ['deployment.create'],
            'source_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'reason' => 'permission_not_granted',
            ],
        ]);
    }

    private function deploymentChannel(string $tenantId, string $deploymentId): string
    {
        return sprintf('private-tenant.%s.deployment.%s', $tenantId, $deploymentId);
    }
}
