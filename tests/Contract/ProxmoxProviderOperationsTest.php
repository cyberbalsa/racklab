<?php

declare(strict_types=1);

use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Jobs\PollProxmoxTask;
use App\Models\Deployment;
use App\Models\DeploymentOperation;
use App\Models\Project;
use App\Models\ProviderTask;
use App\Models\RoleBinding;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Providers\Proxmox\Contracts\ProxmoxClientContract;
use App\Providers\Proxmox\Models\ProxmoxTaskStatus;
use App\Providers\Proxmox\Models\ProxmoxVersion;
use App\Providers\Proxmox\Models\ProxmoxVmCloneRequest;
use App\Providers\Proxmox\Models\ProxmoxVmDeleteRequest;
use App\Providers\Proxmox\Models\ProxmoxVmPowerRequest;
use App\Providers\Proxmox\ProxmoxProviderOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('submits a Proxmox clone once per idempotency key and dispatches polling by persisted UPID', function (): void {
    Queue::fake();

    [$tenant, $operation] = provisionProxmoxCloneOperationFixture();
    $client = new class implements ProxmoxClientContract
    {
        public int $cloneCalls = 0;

        public function version(): ProxmoxVersion
        {
            return ProxmoxVersion::fromStrings('9.2.1', '9.2', null);
        }

        public function cloneVm(ProxmoxVmCloneRequest $request): string
        {
            $this->cloneCalls++;

            return 'UPID:pve01:0009C3C2:067CF15D:6656B4E1:qmclone:101:root@pam:';
        }

        public function deleteVm(ProxmoxVmDeleteRequest $request): string
        {
            return 'UPID:pve01:0009C3C2:067CF15D:6656B4E1:qmdestroy:101:root@pam:';
        }

        public function powerVm(ProxmoxVmPowerRequest $request): string
        {
            return 'UPID:pve01:0009C3C2:067CF15D:6656B4E1:qmstop:101:root@pam:';
        }

        public function taskStatus(string $node, string $upid): ProxmoxTaskStatus
        {
            return new ProxmoxTaskStatus($upid, $node, 'running', null);
        }

        public function taskLog(string $node, string $upid): array
        {
            return [];
        }
    };
    app()->instance(ProxmoxClientContract::class, $client);

    $request = new ProxmoxVmCloneRequest(
        node: 'pve01',
        templateVmid: 9000,
        targetVmid: 101,
        name: 'racklab-101',
        fullClone: true,
        storage: 'local-lvm',
    );

    $first = app(ProxmoxProviderOperations::class)->requestClone($operation, $request);
    $second = app(ProxmoxProviderOperations::class)->requestClone($operation, $request);

    expect($client->cloneCalls)->toBe(1)
        ->and($second->getKey())->toBe($first->getKey())
        ->and(ProviderTask::query()->where('provider', 'proxmox')->count())->toBe(1)
        ->and($first->provider_task_id)->toBe('UPID:pve01:0009C3C2:067CF15D:6656B4E1:qmclone:101:root@pam:')
        ->and($first->proxmox_node)->toBe('pve01')
        ->and($first->proxmox_vm_id)->toBe('101')
        ->and($first->idempotency_key)->toBe($operation->idempotency_key);

    Queue::assertPushed(PollProxmoxTask::class, 1);
    Queue::assertPushed(
        PollProxmoxTask::class,
        static fn (PollProxmoxTask $job): bool => $job->tenantId() === $tenant->getKey()
            && $job->providerTaskId() === $first->getKey(),
    );
});

/**
 * @return array{Tenant, DeploymentOperation}
 */
function provisionProxmoxCloneOperationFixture(): array
{
    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Proxmox Clone User']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    /** @var Project $project */
    $project = Project::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Proxmox Clone Project',
        'slug' => 'proxmox-clone-project',
        'created_for_user_id' => $user->getKey(),
        'is_personal_default' => false,
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);
    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $user->id,
        'role' => 'admin',
        'resource_type' => $project->resourceType(),
        'resource_id' => $project->resourceId(),
        'scope_type' => RoleBindingScopeType::TenantLocal,
        'tenant_id' => $tenant->getKey(),
        'tenant_set' => [$tenant->getKey()],
        'granted_by_id' => $user->getKey(),
        'granted_reason' => 'proxmox clone fixture',
    ]);
    /** @var StackDefinition $stack */
    $stack = StackDefinition::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'name' => 'Proxmox Clone Stack',
        'slug' => 'proxmox-clone-stack',
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
        'name' => 'Proxmox Clone Deployment',
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
        'idempotency_key' => 'proxmox-clone-idempotency',
        'state' => 'pending',
        'requested_diff' => ['template' => 'debian-template'],
    ]);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $operation];
}
