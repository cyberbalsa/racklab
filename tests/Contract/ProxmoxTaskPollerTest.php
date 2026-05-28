<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Jobs\PollProxmoxTask;
use App\Models\Deployment;
use App\Models\DeploymentOperation;
use App\Models\Project;
use App\Models\ProviderTask;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Providers\Proxmox\Contracts\ProxmoxClientContract;
use App\Providers\Proxmox\Exceptions\ProviderTaskWaitTimeout;
use App\Providers\Proxmox\Models\ProxmoxTaskStatus;
use App\Providers\Proxmox\Models\ProxmoxVersion;
use App\Providers\Proxmox\Models\ProxmoxVmCloneRequest;
use App\Providers\Proxmox\Models\ProxmoxVmDeleteRequest;
use App\Providers\Proxmox\Models\ProxmoxVmPowerRequest;
use App\Providers\Proxmox\TaskPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('polls an existing Proxmox UPID to completion without resubmitting the original operation', function (): void {
    [$tenant, $task, $operation] = provisionProxmoxTaskFixture();
    $client = new class extends Tests\Doubles\AbstractProxmoxClientDouble
    {
        public int $statusCalls = 0;

        public int $submitCalls = 0;

        public function version(): ProxmoxVersion
        {
            return ProxmoxVersion::fromStrings('9.2.1', '9.2', null);
        }

        public function cloneVm(ProxmoxVmCloneRequest $request): string
        {
            $this->submitCalls++;

            return 'UPID:pve01:0009C3C2:067CF15D:6656B4E1:qmclone:100:root@pam:';
        }

        public function deleteVm(ProxmoxVmDeleteRequest $request): string
        {
            $this->submitCalls++;

            return 'UPID:pve01:0009C3C2:067CF15D:6656B4E1:qmdestroy:100:root@pam:';
        }

        public function powerVm(ProxmoxVmPowerRequest $request): string
        {
            $this->submitCalls++;

            return 'UPID:pve01:0009C3C2:067CF15D:6656B4E1:qmstop:100:root@pam:';
        }

        public function taskStatus(string $node, string $upid): ProxmoxTaskStatus
        {
            $this->statusCalls++;

            return $this->statusCalls === 1
                ? new ProxmoxTaskStatus($upid, $node, 'running', null)
                : new ProxmoxTaskStatus($upid, $node, 'stopped', 'OK');
        }

        /**
         * @return list<string>
         */
        public function taskLog(string $node, string $upid): array
        {
            return ['clone complete'];
        }
    };
    app()->instance(ProxmoxClientContract::class, $client);

    PollProxmoxTask::dispatchSync($tenant->getKey(), $task->getKey());

    $task->refresh();
    $operation->refresh();

    expect($client->statusCalls)->toBe(2)
        ->and($client->submitCalls)->toBe(0)
        ->and($task->state)->toBe('complete')
        ->and($task->last_polled_at)->not->toBeNull()
        ->and($task->metadata['exitstatus'])->toBe('OK')
        ->and($task->metadata['task_log'])->toBe(['clone complete'])
        ->and($operation->state)->toBe('complete')
        ->and($operation->result['provider_task_id'])->toBe($task->provider_task_id);
});

it('stops waiting on a still-running Proxmox task while leaving the original operation pending', function (): void {
    [, $task, $operation] = provisionProxmoxTaskFixture(idempotencyKey: 'proxmox-wait-timeout');
    $client = new class extends Tests\Doubles\AbstractProxmoxClientDouble
    {
        public int $statusCalls = 0;

        public int $submitCalls = 0;

        public function version(): ProxmoxVersion
        {
            return ProxmoxVersion::fromStrings('9.2.1', '9.2', null);
        }

        public function cloneVm(ProxmoxVmCloneRequest $request): string
        {
            $this->submitCalls++;

            return 'UPID:pve01:0009C3C2:067CF15D:6656B4E1:qmclone:100:root@pam:';
        }

        public function deleteVm(ProxmoxVmDeleteRequest $request): string
        {
            $this->submitCalls++;

            return 'UPID:pve01:0009C3C2:067CF15D:6656B4E1:qmdestroy:100:root@pam:';
        }

        public function powerVm(ProxmoxVmPowerRequest $request): string
        {
            $this->submitCalls++;

            return 'UPID:pve01:0009C3C2:067CF15D:6656B4E1:qmstop:100:root@pam:';
        }

        public function taskStatus(string $node, string $upid): ProxmoxTaskStatus
        {
            $this->statusCalls++;

            return new ProxmoxTaskStatus($upid, $node, 'running', null);
        }

        /**
         * @return list<string>
         */
        public function taskLog(string $node, string $upid): array
        {
            return [];
        }
    };
    app()->instance(ProxmoxClientContract::class, $client);

    expect(fn () => app(TaskPoller::class)->pollUntilTerminal($task->getKey(), maxPolls: 1))
        ->toThrow(ProviderTaskWaitTimeout::class);

    $task->refresh();
    $operation->refresh();

    expect($client->statusCalls)->toBe(1)
        ->and($client->submitCalls)->toBe(0)
        ->and($task->state)->toBe('pending')
        ->and($task->last_polled_at)->not->toBeNull()
        ->and($operation->state)->toBe('pending');
});

/**
 * @return array{Tenant, ProviderTask, DeploymentOperation}
 */
function provisionProxmoxTaskFixture(string $idempotencyKey = 'proxmox-clone-001'): array
{
    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Proxmox Operator']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    /** @var Project $project */
    $project = Project::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Proxmox Project',
        'slug' => 'proxmox-project-'.$idempotencyKey,
        'created_for_user_id' => $user->getKey(),
        'is_personal_default' => false,
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);
    /** @var StackDefinition $stack */
    $stack = StackDefinition::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'name' => 'Proxmox Stack',
        'slug' => 'proxmox-stack-'.$idempotencyKey,
        'scope' => 'project_local',
        'is_reserved_default' => false,
        'definition' => ['version' => 1, 'components' => []],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);
    /** @var Deployment $deployment */
    $deployment = Deployment::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'stack_definition_id' => $stack->getKey(),
        'requested_by_id' => $user->getKey(),
        'name' => 'Proxmox VM',
        'state' => 'pending',
        'provider' => 'proxmox',
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);
    /** @var DeploymentOperation $operation */
    $operation = DeploymentOperation::query()->create([
        'tenant_id' => $tenant->getKey(),
        'deployment_id' => $deployment->getKey(),
        'project_id' => $project->getKey(),
        'stack_definition_id' => $stack->getKey(),
        'actor_user_id' => $user->getKey(),
        'kind' => 'clone',
        'idempotency_key' => $idempotencyKey,
        'state' => 'pending',
        'requested_diff' => ['template' => 'debian-template'],
    ]);
    $upid = 'UPID:pve01:0009C3C2:067CF15D:6656B4E1:qmclone:100:root@pam:';
    /** @var ProviderTask $task */
    $task = ProviderTask::query()->create([
        'tenant_id' => $tenant->getKey(),
        'deployment_id' => $deployment->getKey(),
        'deployment_operation_id' => $operation->getKey(),
        'provider' => 'proxmox',
        'provider_task_id' => $upid,
        'upid' => $upid,
        'proxmox_node' => 'pve01',
        'proxmox_pid' => 0x0009C3C2,
        'proxmox_starttime' => 0x6656B4E1,
        'proxmox_type' => 'qmclone',
        'proxmox_vm_id' => '100',
        'proxmox_user' => 'root@pam',
        'idempotency_key' => $idempotencyKey,
        'operation_class' => 'clone',
        'action' => 'clone',
        'state' => 'pending',
        'attempts' => 1,
        'attempt_count' => 1,
        'metadata' => [],
    ]);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $task, $operation];
}
