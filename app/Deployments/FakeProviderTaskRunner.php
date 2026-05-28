<?php

declare(strict_types=1);

namespace App\Deployments;

use App\Audit\AuditEventWriter;
use App\Broadcasting\BroadcastEventLogWriter;
use App\Models\Deployment;
use App\Models\DeploymentOperation;
use App\Models\DeploymentResource;
use App\Models\DeploymentStateTransition;
use App\Models\ProviderTask;
use App\Models\User;
use App\Networking\DeploymentNetworkBinder;
use App\Quota\QuotaReservationService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class FakeProviderTaskRunner
{
    public function __construct(
        private AuditEventWriter $auditEvents,
        private BroadcastEventLogWriter $broadcastEvents,
        private QuotaReservationService $quota,
        private DeploymentNetworkBinder $networkBinder,
    ) {}

    public function run(string $providerTaskId): ProviderTask
    {
        return DB::transaction(function () use ($providerTaskId): ProviderTask {
            /** @var ProviderTask $task */
            $task = ProviderTask::query()
                ->whereKey($providerTaskId)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($task->state, ['complete', 'failed'], true)) {
                return $task;
            }

            $task->forceFill(['state' => 'running'])->save();

            /** @var Deployment $deployment */
            $deployment = Deployment::query()
                ->whereKey($task->deployment_id)
                ->lockForUpdate()
                ->firstOrFail();
            /** @var DeploymentOperation $operation */
            $operation = DeploymentOperation::query()
                ->whereKey($task->deployment_operation_id)
                ->firstOrFail();

            $metadata = $this->metadata($task);
            $simulateFailure = ($metadata['simulate_failure'] ?? false) === true;
            $errorMessage = $simulateFailure ? 'Fake provider failure requested by test input.' : null;
            $fromState = $deployment->state;
            $resource = $this->applyProviderAction($deployment, $operation, $task, $metadata, $simulateFailure);
            $targetState = $this->targetStateForOperation($task->action, $deployment, $simulateFailure);

            if ($simulateFailure && $resource instanceof DeploymentResource) {
                $resource->forceFill(['state' => 'failed'])->save();
            }

            $deployment->forceFill(['state' => $targetState])->save();
            $operation->forceFill([
                'state' => $simulateFailure ? 'failed' : 'complete',
                'result' => [
                    'deployment_resource_id' => $resource?->getKey(),
                    'provider_task_id' => $task->provider_task_id,
                ],
                'error_message' => $errorMessage,
            ])->save();
            $task->forceFill([
                'state' => $simulateFailure ? 'failed' : 'complete',
                'error_message' => $errorMessage,
                'metadata' => [
                    ...$metadata,
                    'deployment_state' => $targetState,
                    'deployment_resource_id' => $resource?->getKey(),
                ],
            ])->save();
            $this->applyQuotaOutcome($deployment, $operation, $task, $resource, $simulateFailure);
            $this->applyNetworkOutcome($deployment, $operation, $task, $resource, $simulateFailure);

            DeploymentStateTransition::query()->create([
                'tenant_id' => $deployment->tenant_id,
                'deployment_id' => $deployment->getKey(),
                'deployment_operation_id' => $operation->getKey(),
                'from_state' => $fromState,
                'to_state' => $targetState,
                'reason' => $simulateFailure ? 'fake_provider_failed' : 'fake_provider_completed',
                'metadata' => [
                    'resource_id' => $resource?->getKey(),
                    'provider_task_id' => $task->provider_task_id,
                    'error' => $errorMessage,
                ],
            ]);

            $this->auditLifecycle($deployment, $operation, $task, $targetState, $fromState, $simulateFailure, $errorMessage);
            $this->broadcastLifecycle($deployment, $operation, $task, $resource, $targetState, $fromState, $errorMessage);

            return $task->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function applyProviderAction(
        Deployment $deployment,
        DeploymentOperation $operation,
        ProviderTask $task,
        array $metadata,
        bool $simulateFailure,
    ): ?DeploymentResource {
        if (in_array($task->action, ['deploy', 'add_vm'], true)) {
            $componentKey = is_string($metadata['component_key'] ?? null)
                ? $metadata['component_key']
                : $this->nextComponentKey($deployment);

            /** @var DeploymentResource|null $existing */
            $existing = DeploymentResource::query()
                ->where('deployment_id', $deployment->getKey())
                ->where('component_key', $componentKey)
                ->first();

            if ($existing instanceof DeploymentResource) {
                return $existing;
            }

            /** @var DeploymentResource $resource */
            $resource = DeploymentResource::query()->create([
                'tenant_id' => $deployment->tenant_id,
                'deployment_id' => $deployment->getKey(),
                'component_key' => $componentKey,
                'kind' => 'vm',
                'state' => $simulateFailure ? 'failed' : 'running',
                'provider' => 'fake',
                'provider_resource_id' => $simulateFailure ? null : 'fake-'.$componentKey,
                'metadata' => [
                    'operation_id' => $operation->getKey(),
                    'provider_task_id' => $task->provider_task_id,
                ],
            ]);

            return $resource;
        }

        if ($task->action === 'rebuild_stack') {
            DeploymentResource::query()
                ->where('deployment_id', $deployment->getKey())
                ->where('state', '!=', 'removed')
                ->update(['state' => $simulateFailure ? 'failed' : 'running']);

            return null;
        }

        if ($task->action === 'release') {
            DeploymentResource::query()
                ->where('deployment_id', $deployment->getKey())
                ->update(['state' => 'released']);

            return null;
        }

        $resource = $this->deploymentResource($deployment, $metadata);
        $resource->forceFill([
            'state' => match ($task->action) {
                'remove_vm' => 'removed',
                default => $simulateFailure ? 'failed' : 'running',
            },
        ])->save();

        return $resource;
    }

    private function applyQuotaOutcome(
        Deployment $deployment,
        DeploymentOperation $operation,
        ProviderTask $task,
        ?DeploymentResource $resource,
        bool $simulateFailure,
    ): void {
        if ($simulateFailure) {
            $this->quota->releaseForOperation($operation, 'fake_provider_failed');

            return;
        }

        if (in_array($task->action, ['deploy', 'add_vm'], true)) {
            $this->quota->consumeForOperation($operation);

            return;
        }

        if ($task->action === 'release') {
            $this->quota->releaseForDeployment($deployment, 'deployment_released');

            return;
        }

        if ($task->action === 'remove_vm' && $resource instanceof DeploymentResource) {
            $this->releaseRemovedResourceQuota($resource);
        }
    }

    private function releaseRemovedResourceQuota(DeploymentResource $resource): void
    {
        $metadata = $resource->metadata ?? [];
        $operationId = $metadata['operation_id'] ?? null;

        if (! is_string($operationId) || trim($operationId) === '') {
            return;
        }

        /** @var DeploymentOperation|null $operation */
        $operation = DeploymentOperation::query()->whereKey($operationId)->first();

        if ($operation instanceof DeploymentOperation) {
            $this->quota->releaseForOperationDimensions($operation, ['vcpu'], 'deployment_resource_removed');
        }
    }

    private function applyNetworkOutcome(
        Deployment $deployment,
        DeploymentOperation $operation,
        ProviderTask $task,
        ?DeploymentResource $resource,
        bool $simulateFailure,
    ): void {
        if ($simulateFailure) {
            return;
        }

        if (in_array($task->action, ['deploy', 'add_vm'], true) && $resource instanceof DeploymentResource) {
            $this->networkBinder->attachForResource($deployment, $operation, $resource);

            return;
        }

        if ($task->action === 'release') {
            $this->networkBinder->releaseForDeployment($deployment);

            return;
        }

        if ($task->action === 'remove_vm' && $resource instanceof DeploymentResource) {
            $this->networkBinder->releaseForResource($resource);
        }
    }

    private function targetStateForOperation(string $operationKind, Deployment $deployment, bool $simulateFailure): string
    {
        if ($simulateFailure) {
            return 'failed';
        }

        if ($operationKind === 'release') {
            return 'released';
        }

        if (
            $operationKind === 'remove_vm'
            && DeploymentResource::query()
                ->where('deployment_id', $deployment->getKey())
                ->whereNotIn('state', ['removed', 'released'])
                ->doesntExist()
        ) {
            return 'empty';
        }

        return 'running';
    }

    private function nextComponentKey(Deployment $deployment): string
    {
        return sprintf('vm-%d', DeploymentResource::query()
            ->where('deployment_id', $deployment->getKey())
            ->count() + 1);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function deploymentResource(Deployment $deployment, array $metadata): DeploymentResource
    {
        $deploymentResourceId = $metadata['deployment_resource_id'] ?? null;

        if (! is_string($deploymentResourceId) || trim($deploymentResourceId) === '') {
            throw new NotFoundHttpException('Deployment resource not found.');
        }

        /** @var DeploymentResource|null $resource */
        $resource = DeploymentResource::query()
            ->where('deployment_id', $deployment->getKey())
            ->whereKey($deploymentResourceId)
            ->first();

        if (! $resource instanceof DeploymentResource) {
            throw new NotFoundHttpException('Deployment resource not found.');
        }

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(ProviderTask $task): array
    {
        return is_array($task->metadata) ? $task->metadata : [];
    }

    private function auditLifecycle(
        Deployment $deployment,
        DeploymentOperation $operation,
        ProviderTask $task,
        string $targetState,
        string $fromState,
        bool $simulateFailure,
        ?string $errorMessage,
    ): void {
        /** @var User|null $actor */
        $actor = User::query()->whereKey($operation->actor_user_id)->first();
        $metadata = $this->metadata($task);

        $this->auditEvents->append([
            'event_type' => 'deployment.lifecycle',
            'action' => $targetState,
            'result' => $simulateFailure ? 'failed' : 'allowed',
            'actor_type' => $actor instanceof User ? 'user' : 'system',
            'actor_id' => $actor instanceof User ? (string) $actor->id : 'racklab.provider-worker',
            'actor_tenant' => $deployment->tenant_id,
            'resource_type' => 'deployment',
            'resource_id' => $deployment->getKey(),
            'resource_tenant' => $deployment->tenant_id,
            'target_tenant_set' => [$deployment->tenant_id],
            'effective_permissions' => ['deployment.update'],
            'source_ip' => is_string($metadata['source_ip'] ?? null) ? $metadata['source_ip'] : null,
            'user_agent' => is_string($metadata['user_agent'] ?? null) ? $metadata['user_agent'] : null,
            'metadata' => [
                'operation_id' => $operation->getKey(),
                'provider_task_id' => $task->provider_task_id,
                'from_state' => $fromState,
                'provider' => 'fake',
                'error' => $errorMessage,
            ],
        ]);
    }

    private function broadcastLifecycle(
        Deployment $deployment,
        DeploymentOperation $operation,
        ProviderTask $task,
        ?DeploymentResource $resource,
        string $targetState,
        string $fromState,
        ?string $errorMessage,
    ): void {
        $eventClass = in_array($task->action, ['deploy', 'add_vm'], true)
            ? 'App\\Events\\Deployments\\DeploymentStateChanged'
            : 'App\\Events\\Deployments\\DeploymentOperationCompleted';

        $this->broadcastEvents->append(
            tenantId: $deployment->tenant_id,
            channel: sprintf('private-tenant.%s.deployment.%s', $deployment->tenant_id, $deployment->id),
            eventClass: $eventClass,
            payload: [
                'deployment_id' => $deployment->getKey(),
                'operation_id' => $operation->getKey(),
                'resource_id' => $resource?->getKey(),
                'operation' => $task->action,
                'state' => $targetState,
                'from_state' => $fromState,
                'provider' => 'fake',
                'error' => $errorMessage,
            ],
        );
    }
}
