<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Jobs\RunFakeProviderTask;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\DeploymentOperation;
use App\Models\DeploymentResource;
use App\Models\DeploymentStateTransition;
use App\Models\Project;
use App\Models\ProjectDefaultStack;
use App\Models\ProviderTask;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('creates a running deployment from the project default stack through the New VM path', function (): void {
    [$tenant, $user, $project] = provisionDeploymentLifecycleUserProject();

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'new-vm-001',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.project_id', $project->getKey())
        ->assertJsonPath('data.tenant_id', $tenant->getKey())
        ->assertJsonPath('data.state', 'running')
        ->assertJsonPath('data.provider', 'fake')
        ->assertJsonPath('data.operation.kind', 'add_vm')
        ->assertJsonPath('data.resources.0.kind', 'vm')
        ->assertJsonPath('data.resources.0.state', 'running');

    $deploymentId = $response->json('data.id');
    expect($deploymentId)->toBeString();

    $pointer = ProjectDefaultStack::query()->where('project_id', $project->getKey())->firstOrFail();

    expect($pointer->active_deployment_id)->toBe($deploymentId)
        ->and(Deployment::query()->whereKey($deploymentId)->where('state', 'running')->exists())->toBeTrue()
        ->and(DeploymentOperation::query()->where('deployment_id', $deploymentId)->where('kind', 'add_vm')->where('idempotency_key', 'new-vm-001')->exists())->toBeTrue()
        ->and(DeploymentResource::query()->where('deployment_id', $deploymentId)->where('state', 'running')->exists())->toBeTrue()
        ->and(ProviderTask::query()->where('deployment_id', $deploymentId)->where('state', 'complete')->exists())->toBeTrue()
        ->and(DeploymentStateTransition::query()->where('deployment_id', $deploymentId)->where('from_state', 'pending')->where('to_state', 'running')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', 'deployment.request')->where('action', 'add_vm')->where('result', 'allowed')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', 'deployment.lifecycle')->where('action', 'running')->where('result', 'allowed')->exists())->toBeTrue();
});

it('queues fake provider execution behind a pending provider task', function (): void {
    Queue::fake();
    [, $user, $project] = provisionDeploymentLifecycleUserProject();

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'queued-new-vm',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.state', 'pending')
        ->assertJsonPath('data.operation.state', 'pending')
        ->assertJsonCount(0, 'data.resources');

    $task = ProviderTask::query()->firstOrFail();

    expect($task->state)->toBe('pending')
        ->and($task->action)->toBe('add_vm')
        ->and(DeploymentResource::query()->count())->toBe(0)
        ->and(DeploymentStateTransition::query()->where('to_state', 'running')->exists())->toBeFalse();

    Queue::assertPushed(
        RunFakeProviderTask::class,
        static fn (RunFakeProviderTask $job): bool => $job->tenantId() === $task->tenant_id
            && $job->providerTaskId() === $task->getKey(),
    );
});

it('reconciles an existing pending provider task without submitting a duplicate operation', function (): void {
    Queue::fake();
    [, $user, $project] = provisionDeploymentLifecycleUserProject();

    Sanctum::actingAs($user);

    $deploymentId = $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'reconcile-new-vm',
    ])->assertCreated()->json('data.id');
    $task = ProviderTask::query()->firstOrFail();
    $task->forceFill(['updated_at' => now()->subMinutes(10)])->save();

    $this->artisan('racklab:reconcile-provider-tasks')
        ->assertExitCode(0);

    expect(ProviderTask::query()->count())->toBe(1)
        ->and(ProviderTask::query()->firstOrFail()->state)->toBe('complete')
        ->and(DeploymentOperation::query()->count())->toBe(1)
        ->and(Deployment::query()->whereKey($deploymentId)->firstOrFail()->state)->toBe('running')
        ->and(DeploymentResource::query()->where('deployment_id', $deploymentId)->count())->toBe(1);
});

it('returns the original deployment for a duplicate idempotency key', function (): void {
    [, $user, $project] = provisionDeploymentLifecycleUserProject();

    Sanctum::actingAs($user);

    $payload = [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'new-vm-repeat',
    ];

    $first = $this->postJson('/api/v1/deployments', $payload)->assertCreated();
    $second = $this->postJson('/api/v1/deployments', $payload)
        ->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'))
        ->assertJsonPath('data.idempotent_replay', true);

    expect($second->json('data.operation.id'))->toBe($first->json('data.operation.id'))
        ->and(Deployment::query()->count())->toBe(1)
        ->and(DeploymentOperation::query()->count())->toBe(1);
});

it('records fake provider failures as failed deployments with audit context', function (): void {
    [, $user, $project] = provisionDeploymentLifecycleUserProject();

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'new-vm-fails',
        'simulate_failure' => true,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.state', 'failed')
        ->assertJsonPath('data.operation.state', 'failed')
        ->assertJsonPath('data.resources.0.state', 'failed');

    $deploymentId = $response->json('data.id');
    expect($deploymentId)->toBeString()
        ->and(Deployment::query()->whereKey($deploymentId)->where('state', 'failed')->exists())->toBeTrue()
        ->and(DeploymentOperation::query()->where('deployment_id', $deploymentId)->where('state', 'failed')->whereNotNull('error_message')->exists())->toBeTrue()
        ->and(ProviderTask::query()->where('deployment_id', $deploymentId)->where('state', 'failed')->whereNotNull('error_message')->exists())->toBeTrue()
        ->and(DeploymentStateTransition::query()->where('deployment_id', $deploymentId)->where('from_state', 'pending')->where('to_state', 'failed')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', 'deployment.lifecycle')->where('action', 'failed')->where('result', 'failed')->exists())->toBeTrue();
});

it('adds multiple VMs to the active default stack deployment', function (): void {
    [, $user, $project] = provisionDeploymentLifecycleUserProject();

    Sanctum::actingAs($user);

    $first = $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'multi-vm-001',
    ])->assertCreated()->json('data');

    $second = $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'multi-vm-002',
    ])->assertCreated()
        ->assertJsonPath('data.id', $first['id'])
        ->assertJsonCount(2, 'data.resources')
        ->json('data');

    expect($second['resources'][1]['component_key'])->toBe('vm-2')
        ->and(Deployment::query()->count())->toBe(1)
        ->and(DeploymentResource::query()->where('deployment_id', $first['id'])->count())->toBe(2)
        ->and(DeploymentOperation::query()->where('deployment_id', $first['id'])->count())->toBe(2);
});

it('runs rebuild, remove, and release operations against an existing deployment', function (): void {
    [, $user, $project] = provisionDeploymentLifecycleUserProject();

    Sanctum::actingAs($user);

    $deployment = $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'operate-new-vm',
    ])->assertCreated()->json('data');
    $resourceId = $deployment['resources'][0]['id'];

    $this->postJson('/api/v1/deployments/'.$deployment['id'].'/operations', [
        'kind' => 'rebuild_vm',
        'deployment_resource_id' => $resourceId,
        'idempotency_key' => 'operate-rebuild-vm',
    ])
        ->assertCreated()
        ->assertJsonPath('data.operation.kind', 'rebuild_vm')
        ->assertJsonPath('data.resources.0.state', 'running');

    $this->postJson('/api/v1/deployments/'.$deployment['id'].'/operations', [
        'kind' => 'remove_vm',
        'deployment_resource_id' => $resourceId,
        'idempotency_key' => 'operate-remove-vm',
    ])
        ->assertCreated()
        ->assertJsonPath('data.operation.kind', 'remove_vm')
        ->assertJsonPath('data.resources.0.state', 'removed');

    $this->postJson('/api/v1/deployments/'.$deployment['id'].'/operations', [
        'kind' => 'release',
        'idempotency_key' => 'operate-release',
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'released')
        ->assertJsonPath('data.resources.0.state', 'released');

    expect(DeploymentOperation::query()->where('deployment_id', $deployment['id'])->count())->toBe(4)
        ->and(DeploymentStateTransition::query()->where('deployment_id', $deployment['id'])->where('to_state', 'released')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', 'deployment.lifecycle')->where('action', 'released')->exists())->toBeTrue();
});

it('expires leased deployments and releases their fake resources', function (): void {
    [, $user, $project] = provisionDeploymentLifecycleUserProject();

    Sanctum::actingAs($user);

    $deployment = $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'leased-new-vm',
    ])->assertCreated()->json('data');

    Deployment::query()
        ->whereKey($deployment['id'])
        ->update(['lease_expires_at' => now()->subMinute()]);

    $this->artisan('racklab:expire-deployments')
        ->assertExitCode(0);

    expect(Deployment::query()->whereKey($deployment['id'])->firstOrFail()->state)->toBe('expired')
        ->and(DeploymentResource::query()->where('deployment_id', $deployment['id'])->firstOrFail()->state)->toBe('released')
        ->and(DeploymentStateTransition::query()->where('deployment_id', $deployment['id'])->where('to_state', 'expired')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', 'deployment.lifecycle')->where('action', 'expired')->where('result', 'allowed')->exists())->toBeTrue();
});

it('lists and retrieves only deployments readable by the authenticated user', function (): void {
    [, $user, $project] = provisionDeploymentLifecycleUserProject();
    [, $otherUser] = provisionDeploymentLifecycleUserProject(email: 'other@example.test');
    $otherProject = Project::query()->where('created_for_user_id', $otherUser->getKey())->firstOrFail();

    Sanctum::actingAs($user);

    $deployment = $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'visible-deployment',
    ])->assertCreated()->json('data');

    Sanctum::actingAs($otherUser);

    $otherDeployment = $this->postJson('/api/v1/deployments', [
        'project_id' => $otherProject->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'hidden-deployment',
    ])->assertCreated()->json('data');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/deployments')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $deployment['id']);

    $this->getJson('/api/v1/deployments/'.$deployment['id'])
        ->assertOk()
        ->assertJsonPath('data.id', $deployment['id']);

    $this->getJson('/api/v1/deployments/'.$otherDeployment['id'])
        ->assertNotFound();
});

it('lets an authenticated user create a new VM from the dashboard', function (): void {
    [, $user, $project] = provisionDeploymentLifecycleUserProject();

    $this->actingAs($user)
        ->post('/deployments/new-vm', ['project_id' => $project->getKey()])
        ->assertRedirect('/dashboard');

    $deployment = Deployment::query()->firstOrFail();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Deployments')
        ->assertSee($deployment->name)
        ->assertSee('running');
});

it('lets an authenticated user release a deployment from the dashboard', function (): void {
    [, $user, $project] = provisionDeploymentLifecycleUserProject();

    Sanctum::actingAs($user);

    $deployment = $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'dashboard-release-new-vm',
    ])->assertCreated()->json('data');

    $this->actingAs($user)
        ->post('/deployments/'.$deployment['id'].'/release')
        ->assertRedirect('/dashboard');

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('released');
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionDeploymentLifecycleUserProject(?string $email = null): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(
        ['slug' => 'default'],
        ['name' => 'Default Tenant'],
    );
    $user = User::factory()->create([
        'name' => 'M2 Student',
        'email' => $email ?? fake()->unique()->safeEmail(),
    ]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}
