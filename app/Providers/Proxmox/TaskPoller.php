<?php

declare(strict_types=1);

namespace App\Providers\Proxmox;

use App\Models\Deployment;
use App\Models\DeploymentOperation;
use App\Models\DeploymentResource;
use App\Models\DeploymentStateTransition;
use App\Models\ProviderTask;
use App\Networking\DeploymentNetworkBinder;
use App\Providers\Proxmox\Contracts\ProxmoxClientContract;
use App\Providers\Proxmox\Exceptions\ProviderTaskFailed;
use App\Providers\Proxmox\Exceptions\ProviderTaskWaitTimeout;
use App\Providers\Proxmox\Models\ProxmoxTaskStatus;
use App\Providers\Proxmox\Models\ProxmoxUpid;
use App\Quota\QuotaReservationService;
use Illuminate\Support\Facades\DB;

final readonly class TaskPoller
{
    public function __construct(
        private ProxmoxClientContract $client,
        private QuotaReservationService $quota,
        private DeploymentNetworkBinder $networkBinder,
    ) {}

    public function pollUntilTerminal(string $providerTaskId, int $maxPolls = 5): ProviderTask
    {
        $task = $this->task($providerTaskId);
        $upid = ProxmoxUpid::parse($task->upid ?? $task->provider_task_id);
        $this->backfillUpid($task, $upid);

        for ($poll = 0; $poll < $maxPolls; $poll++) {
            $status = $this->client->taskStatus($upid->node, $upid->raw);
            $task = $this->recordPoll($task->refresh(), $status);

            if ($status->successful()) {
                return $this->completeTask($task->refresh(), $status, $this->client->taskLog($upid->node, $upid->raw));
            }

            if ($status->stopped()) {
                return $this->failTask($task->refresh(), $status, $this->client->taskLog($upid->node, $upid->raw));
            }
        }

        $this->stopWaiting($task->refresh());

        throw new ProviderTaskWaitTimeout($task->provider_task_id);
    }

    private function task(string $providerTaskId): ProviderTask
    {
        /** @var ProviderTask $task */
        $task = ProviderTask::query()->whereKey($providerTaskId)->firstOrFail();

        return $task;
    }

    private function backfillUpid(ProviderTask $task, ProxmoxUpid $upid): void
    {
        $task->forceFill([
            'upid' => $upid->raw,
            'proxmox_node' => $upid->node,
            'proxmox_pid' => $upid->pid,
            'proxmox_pstart' => $upid->pstart,
            'proxmox_starttime' => $upid->startTime,
            'proxmox_type' => $upid->type,
            'proxmox_vm_id' => $upid->id,
            'proxmox_user' => $upid->user,
        ])->save();
    }

    private function recordPoll(ProviderTask $task, ProxmoxTaskStatus $status): ProviderTask
    {
        $task->forceFill([
            'state' => $status->stopped() ? $task->state : 'running',
            'last_polled_at' => now(),
            'attempt_count' => $task->attempt_count + 1,
            'metadata' => [
                ...$this->metadata($task),
                'proxmox_status' => $status->status,
                'exitstatus' => $status->exitStatus,
            ],
        ])->save();

        return $task;
    }

    /**
     * @param  list<string>  $taskLog
     */
    private function completeTask(ProviderTask $task, ProxmoxTaskStatus $status, array $taskLog): ProviderTask
    {
        return DB::transaction(function () use ($task, $status, $taskLog): ProviderTask {
            $task->forceFill([
                'state' => 'complete',
                'error_message' => null,
                'last_polled_at' => now(),
                'metadata' => [
                    ...$this->metadata($task),
                    'proxmox_status' => $status->status,
                    'exitstatus' => $status->exitStatus,
                    'task_log' => $taskLog,
                ],
            ])->save();

            $operation = $this->operation($task);
            $operation->forceFill([
                'state' => 'complete',
                'result' => [
                    'provider' => 'proxmox',
                    'provider_task_id' => $task->provider_task_id,
                    'exitstatus' => $status->exitStatus,
                ],
                'error_message' => null,
            ])->save();
            $this->completeDeploymentResource($task, $status);
            $this->completeQuota($task, $operation);

            return $task->refresh();
        });
    }

    /**
     * @param  list<string>  $taskLog
     */
    private function failTask(ProviderTask $task, ProxmoxTaskStatus $status, array $taskLog): ProviderTask
    {
        $exitStatus = $status->exitStatus ?? 'unknown';

        DB::transaction(function () use ($task, $status, $taskLog, $exitStatus): void {
            $task->forceFill([
                'state' => 'failed',
                'error_message' => $exitStatus,
                'last_polled_at' => now(),
                'metadata' => [
                    ...$this->metadata($task),
                    'proxmox_status' => $status->status,
                    'exitstatus' => $exitStatus,
                    'task_log' => $taskLog,
                ],
            ])->save();

            $operation = $this->operation($task);
            $operation->forceFill([
                'state' => 'failed',
                'result' => [
                    'provider' => 'proxmox',
                    'provider_task_id' => $task->provider_task_id,
                    'exitstatus' => $exitStatus,
                ],
                'error_message' => $exitStatus,
            ])->save();
            $this->quota->releaseForOperation($operation, 'proxmox_task_failed');
        });

        throw new ProviderTaskFailed($task->provider_task_id, $exitStatus);
    }

    private function stopWaiting(ProviderTask $task): void
    {
        $task->forceFill([
            'state' => 'pending',
            'last_polled_at' => now(),
            'metadata' => [
                ...$this->metadata($task),
                'wait_timeout' => true,
            ],
        ])->save();
    }

    private function operation(ProviderTask $task): DeploymentOperation
    {
        /** @var DeploymentOperation $operation */
        $operation = DeploymentOperation::query()->whereKey($task->deployment_operation_id)->firstOrFail();

        return $operation;
    }

    private function completeDeploymentResource(ProviderTask $task, ProxmoxTaskStatus $status): void
    {
        if (! in_array($task->operation_class, ['clone', 'delete', 'power'], true)) {
            return;
        }

        $metadata = $this->metadata($task);
        $deploymentResourceId = $metadata['deployment_resource_id'] ?? null;

        if (! is_string($deploymentResourceId) || trim($deploymentResourceId) === '') {
            return;
        }

        /** @var DeploymentResource|null $resource */
        $resource = DeploymentResource::query()
            ->whereKey($deploymentResourceId)
            ->where('deployment_id', $task->deployment_id)
            ->first();

        if (! $resource instanceof DeploymentResource) {
            return;
        }

        /** @var Deployment $deployment */
        $deployment = Deployment::query()->whereKey($task->deployment_id)->firstOrFail();
        $fromState = $deployment->state;

        $targetState = match ($task->operation_class) {
            'delete' => 'released',
            'power' => ($metadata['power_action'] ?? null) === 'stop' ? 'stopped' : 'running',
            default => 'running',
        };

        $resource->forceFill([
            'state' => $targetState,
            'provider_resource_id' => $task->operation_class === 'delete'
                ? $resource->provider_resource_id
                : $task->proxmox_vm_id,
            'metadata' => [
                ...($resource->metadata ?? []),
                'upid' => $task->provider_task_id,
                'exitstatus' => $status->exitStatus,
            ],
        ])->save();
        $deployment->forceFill(['state' => $targetState])->save();

        DeploymentStateTransition::query()->create([
            'tenant_id' => $deployment->tenant_id,
            'deployment_id' => $deployment->getKey(),
            'deployment_operation_id' => $task->deployment_operation_id,
            'from_state' => $fromState,
            'to_state' => $targetState,
            'reason' => match ($task->operation_class) {
                'delete' => 'proxmox_delete_completed',
                'power' => 'proxmox_power_completed',
                default => 'proxmox_clone_completed',
            },
            'metadata' => [
                'deployment_resource_id' => $resource->getKey(),
                'provider_task_id' => $task->provider_task_id,
                'proxmox_vm_id' => $task->proxmox_vm_id,
            ],
        ]);

        if ($task->operation_class === 'clone') {
            $this->networkBinder->attachForResource($deployment, $this->operation($task), $resource);
        }

        if ($task->operation_class === 'delete') {
            $this->networkBinder->releaseForDeployment($deployment);
        }
    }

    private function completeQuota(ProviderTask $task, DeploymentOperation $operation): void
    {
        if ($task->operation_class === 'clone') {
            $this->quota->consumeForOperation($operation);

            return;
        }

        if ($task->operation_class !== 'delete') {
            return;
        }

        /** @var Deployment $deployment */
        $deployment = Deployment::query()->whereKey($task->deployment_id)->firstOrFail();
        $this->quota->releaseForDeployment($deployment, 'proxmox_delete_completed');
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(ProviderTask $task): array
    {
        return is_array($task->metadata) ? $task->metadata : [];
    }
}
