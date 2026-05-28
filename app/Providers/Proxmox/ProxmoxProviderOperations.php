<?php

declare(strict_types=1);

namespace App\Providers\Proxmox;

use App\Jobs\PollProxmoxTask;
use App\Models\DeploymentOperation;
use App\Models\ProviderTask;
use App\Providers\Proxmox\Contracts\ProxmoxClientContract;
use App\Providers\Proxmox\Models\ProxmoxUpid;
use App\Providers\Proxmox\Models\ProxmoxVmCloneRequest;
use App\Providers\Proxmox\Models\ProxmoxVmDeleteRequest;
use App\Providers\Proxmox\Models\ProxmoxVmPowerRequest;
use Illuminate\Support\Facades\DB;

final readonly class ProxmoxProviderOperations
{
    public function __construct(private ProxmoxClientContract $client) {}

    public function requestClone(DeploymentOperation $operation, ProxmoxVmCloneRequest $request): ProviderTask
    {
        /** @var ProviderTask|null $existing */
        $existing = ProviderTask::query()
            ->where('provider', 'proxmox')
            ->where('idempotency_key', $operation->idempotency_key)
            ->first();

        if ($existing instanceof ProviderTask) {
            return $existing;
        }

        $upid = ProxmoxUpid::parse($this->client->cloneVm($request));

        $task = DB::transaction(function () use ($operation, $request, $upid): ProviderTask {
            /** @var ProviderTask $task */
            $task = ProviderTask::query()->create([
                'tenant_id' => $operation->tenant_id,
                'deployment_id' => $operation->deployment_id,
                'deployment_operation_id' => $operation->getKey(),
                'provider' => 'proxmox',
                'provider_task_id' => $upid->raw,
                'upid' => $upid->raw,
                'proxmox_node' => $upid->node,
                'proxmox_pid' => $upid->pid,
                'proxmox_pstart' => $upid->pstart,
                'proxmox_starttime' => $upid->startTime,
                'proxmox_type' => $upid->type,
                'proxmox_vm_id' => $upid->id,
                'proxmox_user' => $upid->user,
                'idempotency_key' => $operation->idempotency_key,
                'operation_class' => 'clone',
                'action' => 'clone',
                'state' => 'pending',
                'attempts' => 1,
                'attempt_count' => 1,
                'metadata' => [
                    'node' => $request->node,
                    'template_vmid' => $request->templateVmid,
                    'target_vmid' => $request->targetVmid,
                    'name' => $request->name,
                    'full_clone' => $request->fullClone,
                    'storage' => $request->storage,
                ],
            ]);

            PollProxmoxTask::dispatch($operation->tenant_id, $task->resourceId())->afterCommit();

            return $task;
        });

        return $task->refresh();
    }

    public function requestDelete(
        DeploymentOperation $operation,
        ProxmoxVmDeleteRequest $request,
        string $deploymentResourceId,
    ): ProviderTask {
        /** @var ProviderTask|null $existing */
        $existing = ProviderTask::query()
            ->where('provider', 'proxmox')
            ->where('idempotency_key', $operation->idempotency_key)
            ->first();

        if ($existing instanceof ProviderTask) {
            return $existing;
        }

        $upid = ProxmoxUpid::parse($this->client->deleteVm($request));

        $task = DB::transaction(function () use ($operation, $request, $upid, $deploymentResourceId): ProviderTask {
            /** @var ProviderTask $task */
            $task = ProviderTask::query()->create([
                'tenant_id' => $operation->tenant_id,
                'deployment_id' => $operation->deployment_id,
                'deployment_operation_id' => $operation->getKey(),
                'provider' => 'proxmox',
                'provider_task_id' => $upid->raw,
                'upid' => $upid->raw,
                'proxmox_node' => $upid->node,
                'proxmox_pid' => $upid->pid,
                'proxmox_pstart' => $upid->pstart,
                'proxmox_starttime' => $upid->startTime,
                'proxmox_type' => $upid->type,
                'proxmox_vm_id' => $upid->id,
                'proxmox_user' => $upid->user,
                'idempotency_key' => $operation->idempotency_key,
                'operation_class' => 'delete',
                'action' => 'delete',
                'state' => 'pending',
                'attempts' => 1,
                'attempt_count' => 1,
                'metadata' => [
                    'node' => $request->node,
                    'vmid' => $request->vmid,
                    'purge' => $request->purge,
                    'deployment_resource_id' => $deploymentResourceId,
                ],
            ]);

            PollProxmoxTask::dispatch($operation->tenant_id, $task->resourceId())->afterCommit();

            return $task;
        });

        return $task->refresh();
    }

    public function requestPower(
        DeploymentOperation $operation,
        ProxmoxVmPowerRequest $request,
        string $deploymentResourceId,
    ): ProviderTask {
        /** @var ProviderTask|null $existing */
        $existing = ProviderTask::query()
            ->where('provider', 'proxmox')
            ->where('idempotency_key', $operation->idempotency_key)
            ->first();

        if ($existing instanceof ProviderTask) {
            return $existing;
        }

        $upid = ProxmoxUpid::parse($this->client->powerVm($request));

        $task = DB::transaction(function () use ($operation, $request, $upid, $deploymentResourceId): ProviderTask {
            /** @var ProviderTask $task */
            $task = ProviderTask::query()->create([
                'tenant_id' => $operation->tenant_id,
                'deployment_id' => $operation->deployment_id,
                'deployment_operation_id' => $operation->getKey(),
                'provider' => 'proxmox',
                'provider_task_id' => $upid->raw,
                'upid' => $upid->raw,
                'proxmox_node' => $upid->node,
                'proxmox_pid' => $upid->pid,
                'proxmox_pstart' => $upid->pstart,
                'proxmox_starttime' => $upid->startTime,
                'proxmox_type' => $upid->type,
                'proxmox_vm_id' => $upid->id,
                'proxmox_user' => $upid->user,
                'idempotency_key' => $operation->idempotency_key,
                'operation_class' => 'power',
                'action' => $request->action,
                'state' => 'pending',
                'attempts' => 1,
                'attempt_count' => 1,
                'metadata' => [
                    'node' => $request->node,
                    'vmid' => $request->vmid,
                    'power_action' => $request->action,
                    'deployment_resource_id' => $deploymentResourceId,
                ],
            ]);

            PollProxmoxTask::dispatch($operation->tenant_id, $task->resourceId())->afterCommit();

            return $task;
        });

        return $task->refresh();
    }
}
