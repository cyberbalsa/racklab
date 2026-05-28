<?php

declare(strict_types=1);

use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Jobs\PollProxmoxTask;
use App\Models\AuditEvent;
use App\Models\CatalogItem;
use App\Models\CatalogVersion;
use App\Models\Deployment;
use App\Models\DeploymentResource;
use App\Models\DeploymentStateTransition;
use App\Models\Project;
use App\Models\ProviderCapacitySnapshot;
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
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('routes Proxmox-backed catalog deployments through the clone adapter and persists VMID after polling', function (): void {
    Queue::fake();

    [$tenant, $user, $project, $version] = provisionProxmoxCatalogDeploymentFixture();
    $client = new class implements ProxmoxClientContract
    {
        public ?ProxmoxVmCloneRequest $cloneRequest = null;

        public function version(): ProxmoxVersion
        {
            return ProxmoxVersion::fromStrings('9.2.1', '9.2', null);
        }

        public function cloneVm(ProxmoxVmCloneRequest $request): string
        {
            $this->cloneRequest = $request;

            return 'UPID:pve01:0009C3C2:067CF15D:6656B4E1:qmclone:101:root@pam:';
        }

        public function deleteVm(ProxmoxVmDeleteRequest $request): string
        {
            return 'UPID:pve01:0009C3C2:067CF15D:6656B5F0:qmdestroy:101:root@pam:';
        }

        public function powerVm(ProxmoxVmPowerRequest $request): string
        {
            return 'UPID:pve01:0009C3C2:067CF15D:6656B700:qmstop:101:root@pam:';
        }

        public function taskStatus(string $node, string $upid): ProxmoxTaskStatus
        {
            return new ProxmoxTaskStatus($upid, $node, 'stopped', 'OK');
        }

        public function taskLog(string $node, string $upid): array
        {
            return ['clone finished'];
        }
    };
    app()->instance(ProxmoxClientContract::class, $client);

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'catalog_version_id' => $version->getKey(),
        'operation' => 'deploy',
        'idempotency_key' => 'proxmox-catalog-deploy',
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'pending')
        ->assertJsonPath('data.provider', 'proxmox')
        ->assertJsonPath('data.resources.0.provider', 'proxmox')
        ->assertJsonPath('data.resources.0.state', 'pending');

    $deploymentId = $response->json('data.id');
    $resourceId = $response->json('data.resources.0.id');
    $task = ProviderTask::query()->where('provider', 'proxmox')->firstOrFail();

    expect($client->cloneRequest)->toBeInstanceOf(ProxmoxVmCloneRequest::class)
        ->and($client->cloneRequest?->templateVmid)->toBe(9000)
        ->and($client->cloneRequest?->targetVmid)->toBe(101)
        ->and($task->deployment_id)->toBe($deploymentId)
        ->and($task->proxmox_vm_id)->toBe('101')
        ->and($task->metadata['deployment_resource_id'])->toBe($resourceId);

    Queue::assertPushed(
        PollProxmoxTask::class,
        static fn (PollProxmoxTask $job): bool => $job->tenantId() === $tenant->getKey()
            && $job->providerTaskId() === $task->getKey(),
    );

    (new PollProxmoxTask($tenant->getKey(), $task->getKey()))
        ->handle(app(App\Providers\Proxmox\TaskPoller::class));

    expect(Deployment::query()->whereKey($deploymentId)->firstOrFail()->state)->toBe('running')
        ->and(DeploymentResource::query()->whereKey($resourceId)->firstOrFail()->state)->toBe('running')
        ->and(DeploymentResource::query()->whereKey($resourceId)->firstOrFail()->provider_resource_id)->toBe('101')
        ->and(DeploymentStateTransition::query()->where('deployment_id', $deploymentId)->where('to_state', 'running')->exists())->toBeTrue();
});

it('routes Proxmox deployment release operations through the delete adapter', function (): void {
    Queue::fake();

    [$tenant, $user, $project, $version] = provisionProxmoxCatalogDeploymentFixture();
    $client = new class implements ProxmoxClientContract
    {
        public ?ProxmoxVmDeleteRequest $deleteRequest = null;

        public function version(): ProxmoxVersion
        {
            return ProxmoxVersion::fromStrings('9.2.1', '9.2', null);
        }

        public function cloneVm(ProxmoxVmCloneRequest $request): string
        {
            return 'UPID:pve01:0009C3C2:067CF15D:6656B4E1:qmclone:101:root@pam:';
        }

        public function deleteVm(ProxmoxVmDeleteRequest $request): string
        {
            $this->deleteRequest = $request;

            return 'UPID:pve01:0009C3C2:067CF15D:6656B5F0:qmdestroy:101:root@pam:';
        }

        public function powerVm(ProxmoxVmPowerRequest $request): string
        {
            return 'UPID:pve01:0009C3C2:067CF15D:6656B700:qmstop:101:root@pam:';
        }

        public function taskStatus(string $node, string $upid): ProxmoxTaskStatus
        {
            return new ProxmoxTaskStatus($upid, $node, 'stopped', 'OK');
        }

        public function taskLog(string $node, string $upid): array
        {
            return ['task finished'];
        }
    };
    app()->instance(ProxmoxClientContract::class, $client);

    Sanctum::actingAs($user);

    $deployment = $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'catalog_version_id' => $version->getKey(),
        'operation' => 'deploy',
        'idempotency_key' => 'proxmox-release-deploy',
    ])->assertCreated()->json('data');
    $cloneTask = ProviderTask::query()->where('operation_class', 'clone')->firstOrFail();
    (new PollProxmoxTask($tenant->getKey(), $cloneTask->getKey()))
        ->handle(app(App\Providers\Proxmox\TaskPoller::class));

    Queue::fake();

    $this->postJson('/api/v1/deployments/'.$deployment['id'].'/operations', [
        'kind' => 'release',
        'idempotency_key' => 'proxmox-release-operation',
    ])
        ->assertCreated()
        ->assertJsonPath('data.provider', 'proxmox')
        ->assertJsonPath('data.operation.kind', 'release')
        ->assertJsonPath('data.operation.state', 'pending');

    $deleteTask = ProviderTask::query()->where('operation_class', 'delete')->firstOrFail();

    expect($client->deleteRequest)->toBeInstanceOf(ProxmoxVmDeleteRequest::class)
        ->and($client->deleteRequest?->vmid)->toBe(101)
        ->and($deleteTask->metadata['deployment_resource_id'])->toBe($deployment['resources'][0]['id']);

    Queue::assertPushed(
        PollProxmoxTask::class,
        static fn (PollProxmoxTask $job): bool => $job->providerTaskId() === $deleteTask->getKey(),
    );

    (new PollProxmoxTask($tenant->getKey(), $deleteTask->getKey()))
        ->handle(app(App\Providers\Proxmox\TaskPoller::class));

    expect(Deployment::query()->whereKey($deployment['id'])->firstOrFail()->state)->toBe('released')
        ->and(DeploymentResource::query()->whereKey($deployment['resources'][0]['id'])->firstOrFail()->state)->toBe('released')
        ->and(DeploymentStateTransition::query()->where('deployment_id', $deployment['id'])->where('to_state', 'released')->exists())->toBeTrue();
});

it('schedules Proxmox deployments onto an eligible capacity snapshot when no node is pinned', function (): void {
    Queue::fake();

    [$tenant, $user, $project, $version] = provisionProxmoxCatalogDeploymentFixture();
    /** @var StackDefinition $stack */
    $stack = StackDefinition::query()->whereKey($version->stack_definition_id)->firstOrFail();
    $definition = $stack->definition;
    unset($definition['components'][0]['proxmox']['node']);
    $stack->forceFill(['definition' => $definition])->save();

    ProviderCapacitySnapshot::query()->create([
        'tenant_id' => $tenant->getKey(),
        'provider' => 'proxmox',
        'provider_cluster' => 'default',
        'node' => 'pve-maint',
        'healthy' => true,
        'maintenance_mode' => true,
        'available_vcpus' => 32,
        'available_memory_mb' => 131_072,
        'available_storage_gb' => 2000,
        'job_pressure' => 0,
        'templates' => [9000],
        'tags' => [],
        'metadata' => [],
        'observed_at' => now(),
    ]);
    ProviderCapacitySnapshot::query()->create([
        'tenant_id' => $tenant->getKey(),
        'provider' => 'proxmox',
        'provider_cluster' => 'default',
        'node' => 'pve02',
        'healthy' => true,
        'maintenance_mode' => false,
        'available_vcpus' => 8,
        'available_memory_mb' => 16_384,
        'available_storage_gb' => 250,
        'job_pressure' => 0,
        'templates' => [9000],
        'tags' => [],
        'metadata' => [],
        'observed_at' => now(),
    ]);

    $client = new class implements ProxmoxClientContract
    {
        public ?ProxmoxVmCloneRequest $cloneRequest = null;

        public function version(): ProxmoxVersion
        {
            return ProxmoxVersion::fromStrings('9.2.1', '9.2', null);
        }

        public function cloneVm(ProxmoxVmCloneRequest $request): string
        {
            $this->cloneRequest = $request;

            return 'UPID:pve02:0009C3C2:067CF15D:6656B4E1:qmclone:101:root@pam:';
        }

        public function deleteVm(ProxmoxVmDeleteRequest $request): string
        {
            return 'UPID:pve02:0009C3C2:067CF15D:6656B5F0:qmdestroy:101:root@pam:';
        }

        public function powerVm(ProxmoxVmPowerRequest $request): string
        {
            return 'UPID:pve02:0009C3C2:067CF15D:6656B700:qmstop:101:root@pam:';
        }

        public function taskStatus(string $node, string $upid): ProxmoxTaskStatus
        {
            return new ProxmoxTaskStatus($upid, $node, 'stopped', 'OK');
        }

        public function taskLog(string $node, string $upid): array
        {
            return ['clone finished'];
        }
    };
    app()->instance(ProxmoxClientContract::class, $client);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'catalog_version_id' => $version->getKey(),
        'operation' => 'deploy',
        'idempotency_key' => 'proxmox-scheduled-deploy',
    ])->assertCreated();

    $auditEvent = AuditEvent::query()->where('event_type', 'deployment.scheduled')->firstOrFail();

    expect($client->cloneRequest?->node)->toBe('pve02')
        ->and($auditEvent->metadata['selected_node'] ?? null)->toBe('pve02')
        ->and($auditEvent->metadata['candidate_nodes'] ?? [])->toBe(['pve02']);
});

it('routes Proxmox power-off operations through the power adapter', function (): void {
    Queue::fake();

    [$tenant, $user, $project, $version] = provisionProxmoxCatalogDeploymentFixture();
    $client = new class implements ProxmoxClientContract
    {
        public ?ProxmoxVmPowerRequest $powerRequest = null;

        public function version(): ProxmoxVersion
        {
            return ProxmoxVersion::fromStrings('9.2.1', '9.2', null);
        }

        public function cloneVm(ProxmoxVmCloneRequest $request): string
        {
            return 'UPID:pve01:0009C3C2:067CF15D:6656B4E1:qmclone:101:root@pam:';
        }

        public function deleteVm(ProxmoxVmDeleteRequest $request): string
        {
            return 'UPID:pve01:0009C3C2:067CF15D:6656B5F0:qmdestroy:101:root@pam:';
        }

        public function powerVm(ProxmoxVmPowerRequest $request): string
        {
            $this->powerRequest = $request;

            return 'UPID:pve01:0009C3C2:067CF15D:6656B700:qmstop:101:root@pam:';
        }

        public function taskStatus(string $node, string $upid): ProxmoxTaskStatus
        {
            return new ProxmoxTaskStatus($upid, $node, 'stopped', 'OK');
        }

        public function taskLog(string $node, string $upid): array
        {
            return ['task finished'];
        }
    };
    app()->instance(ProxmoxClientContract::class, $client);

    Sanctum::actingAs($user);

    $deployment = $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'catalog_version_id' => $version->getKey(),
        'operation' => 'deploy',
        'idempotency_key' => 'proxmox-power-deploy',
    ])->assertCreated()->json('data');
    $cloneTask = ProviderTask::query()->where('operation_class', 'clone')->firstOrFail();
    (new PollProxmoxTask($tenant->getKey(), $cloneTask->getKey()))
        ->handle(app(App\Providers\Proxmox\TaskPoller::class));

    Queue::fake();

    $this->postJson('/api/v1/deployments/'.$deployment['id'].'/operations', [
        'kind' => 'power_off',
        'deployment_resource_id' => $deployment['resources'][0]['id'],
        'idempotency_key' => 'proxmox-power-off-operation',
    ])
        ->assertCreated()
        ->assertJsonPath('data.operation.kind', 'power_off')
        ->assertJsonPath('data.operation.state', 'pending');

    $powerTask = ProviderTask::query()->where('operation_class', 'power')->firstOrFail();

    expect($client->powerRequest)->toBeInstanceOf(ProxmoxVmPowerRequest::class)
        ->and($client->powerRequest?->action)->toBe('stop')
        ->and($powerTask->metadata['deployment_resource_id'])->toBe($deployment['resources'][0]['id']);

    (new PollProxmoxTask($tenant->getKey(), $powerTask->getKey()))
        ->handle(app(App\Providers\Proxmox\TaskPoller::class));

    expect(Deployment::query()->whereKey($deployment['id'])->firstOrFail()->state)->toBe('stopped')
        ->and(DeploymentResource::query()->whereKey($deployment['resources'][0]['id'])->firstOrFail()->state)->toBe('stopped')
        ->and(DeploymentStateTransition::query()->where('deployment_id', $deployment['id'])->where('to_state', 'stopped')->exists())->toBeTrue();
});

/**
 * @return array{Tenant, User, Project, CatalogVersion}
 */
function provisionProxmoxCatalogDeploymentFixture(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Proxmox Catalog Student']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    /** @var Project $project */
    $project = Project::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Proxmox Catalog Project',
        'slug' => 'proxmox-catalog-project',
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
        'granted_reason' => 'proxmox catalog fixture',
    ]);
    /** @var StackDefinition $stack */
    $stack = StackDefinition::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => null,
        'name' => 'Proxmox Ubuntu',
        'slug' => 'proxmox-ubuntu',
        'scope' => 'catalog',
        'is_reserved_default' => false,
        'definition' => [
            'provider' => 'proxmox',
            'components' => [[
                'key' => 'vm',
                'kind' => 'vm',
                'provider' => 'proxmox',
                'proxmox' => [
                    'node' => 'pve01',
                    'template_vmid' => 9000,
                    'target_vmid' => 101,
                    'name' => 'racklab-101',
                    'full_clone' => true,
                    'storage' => 'local-lvm',
                ],
            ]],
        ],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);
    /** @var CatalogItem $item */
    $item = CatalogItem::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Proxmox Ubuntu',
        'slug' => 'proxmox-ubuntu',
        'description' => 'A Proxmox-backed catalog item.',
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);
    /** @var CatalogVersion $version */
    $version = CatalogVersion::query()->create([
        'tenant_id' => $tenant->getKey(),
        'catalog_item_id' => $item->getKey(),
        'stack_definition_id' => $stack->getKey(),
        'version' => '1.0.0',
        'state' => 'published',
        'published_at' => now(),
        'summary' => 'Proxmox version.',
    ]);
    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $user->id,
        'role' => 'student',
        'resource_type' => $item->resourceType(),
        'resource_id' => $item->resourceId(),
        'scope_type' => RoleBindingScopeType::TenantLocal,
        'tenant_id' => $tenant->getKey(),
        'tenant_set' => [$tenant->getKey()],
        'granted_by_id' => $user->getKey(),
        'granted_reason' => 'proxmox catalog read fixture',
    ]);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project, $version];
}
