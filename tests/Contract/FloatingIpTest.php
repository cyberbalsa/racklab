<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\DeploymentNetworkBinding;
use App\Models\DeploymentResource;
use App\Models\FloatingIp;
use App\Models\FloatingIpPool;
use App\Models\NetworkOffering;
use App\Models\Project;
use App\Models\ProviderNetwork;
use App\Models\QuotaEvent;
use App\Models\QuotaLimit;
use App\Models\QuotaUsage;
use App\Models\RoleBinding;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('allocates a floating IP from a pool and maps it to a deployment network binding', function (): void {
    [$tenant, $user, $project] = provisionFloatingIpProject();
    $pool = createFloatingIpPool($tenant, 'public-test-pool', '198.51.100.0/30');
    $binding = createFloatingIpDeploymentBinding($tenant, $user, $project);
    createFloatingIpQuota($tenant, $project, 1);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/floating-ips', [
        'project_id' => $project->getKey(),
        'floating_ip_pool_slug' => 'public-test-pool',
        'deployment_network_binding_id' => $binding->getKey(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.address', '198.51.100.1')
        ->assertJsonPath('data.state', 'allocated')
        ->assertJsonPath('data.deployment_network_binding_id', $binding->getKey());

    expect(FloatingIp::query()->where('address', '198.51.100.1')->where('state', 'allocated')->exists())->toBeTrue()
        ->and(QuotaUsage::query()->where('dimension', 'floating_ips')->where('state', 'active')->count())->toBe(1)
        ->and(QuotaEvent::query()->where('event_type', 'quota.consumed')->where('dimension', 'floating_ips')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', 'network.floating_ip')->where('result', 'allowed')->exists())->toBeTrue();
});

it('releases a floating IP and returns quota capacity and the address to the pool', function (): void {
    [$tenant, $user, $project] = provisionFloatingIpProject();
    createFloatingIpPool($tenant, 'release-pool', '198.51.100.0/30');
    createFloatingIpQuota($tenant, $project, 1);

    Sanctum::actingAs($user);

    $firstId = $this->postJson('/api/v1/floating-ips', [
        'project_id' => $project->getKey(),
        'floating_ip_pool_slug' => 'release-pool',
    ])
        ->assertCreated()
        ->assertJsonPath('data.address', '198.51.100.1')
        ->json('data.id');

    expect($firstId)->toBeString();

    $this->deleteJson('/api/v1/floating-ips/'.$firstId)->assertNoContent();

    /** @var FloatingIp $released */
    $released = FloatingIp::query()->whereKey($firstId)->firstOrFail();

    expect($released->state)->toBe('released')
        ->and($released->released_at)->not->toBeNull()
        ->and(QuotaUsage::query()->where('dimension', 'floating_ips')->where('state', 'released')->count())->toBe(1)
        ->and(QuotaEvent::query()->where('event_type', 'quota.released')->where('dimension', 'floating_ips')->exists())->toBeTrue();

    $this->postJson('/api/v1/floating-ips', [
        'project_id' => $project->getKey(),
        'floating_ip_pool_slug' => 'release-pool',
    ])
        ->assertCreated()
        ->assertJsonPath('data.address', '198.51.100.1');
});

it('denies floating IP allocation without the public IP permission and audits the denial', function (): void {
    [$tenant, $user, $project] = provisionFloatingIpProject();
    createFloatingIpPool($tenant, 'denied-pool', '198.51.100.4/30');

    RoleBinding::query()
        ->where('principal_type', 'user')
        ->where('principal_id', (string) $user->id)
        ->delete();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/floating-ips', [
        'project_id' => $project->getKey(),
        'floating_ip_pool_slug' => 'denied-pool',
    ])->assertForbidden();

    expect(FloatingIp::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', 'network.floating_ip')->where('result', 'denied')->exists())->toBeTrue();
});

it('denies floating IP allocation when quota is exhausted', function (): void {
    [$tenant, $user, $project] = provisionFloatingIpProject();
    createFloatingIpPool($tenant, 'quota-pool', '198.51.100.8/30');
    createFloatingIpQuota($tenant, $project, 0);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/floating-ips', [
        'project_id' => $project->getKey(),
        'floating_ip_pool_slug' => 'quota-pool',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quota');

    expect(FloatingIp::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', 'quota.denied')->where('action', 'network.floating_ip.allocate')->exists())->toBeTrue();
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionFloatingIpProject(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create([
        'name' => 'Floating IP Student',
        'email' => fake()->unique()->safeEmail(),
    ]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}

function createFloatingIpPool(Tenant $tenant, string $slug, string $cidr): FloatingIpPool
{
    /** @var ProviderNetwork $providerNetwork */
    $providerNetwork = ProviderNetwork::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Floating IP Provider '.$slug,
        'slug' => 'floating-provider-'.$slug,
        'provider' => 'fake',
        'provider_cluster' => null,
        'network_type' => 'bridge',
        'external_id' => 'vmbr-floating-'.$slug,
        'bridge' => 'vmbr-floating-'.$slug,
        'vlan_tag' => null,
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    /** @var FloatingIpPool $pool */
    $pool = FloatingIpPool::query()->create([
        'tenant_id' => $tenant->getKey(),
        'provider_network_id' => $providerNetwork->getKey(),
        'name' => 'Floating IP Pool '.$slug,
        'slug' => $slug,
        'cidr' => $cidr,
        'ip_version' => 4,
        'provider' => 'fake',
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    return $pool;
}

function createFloatingIpDeploymentBinding(Tenant $tenant, User $user, Project $project): DeploymentNetworkBinding
{
    /** @var StackDefinition $stack */
    $stack = StackDefinition::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'name' => 'Floating IP Stack',
        'slug' => 'floating-ip-stack',
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
        'name' => 'Floating IP Deployment',
        'state' => 'running',
        'provider' => 'fake',
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    /** @var DeploymentResource $resource */
    $resource = DeploymentResource::query()->create([
        'tenant_id' => $tenant->getKey(),
        'deployment_id' => $deployment->getKey(),
        'component_key' => 'vm',
        'kind' => 'vm',
        'state' => 'running',
        'provider' => 'fake',
        'provider_resource_id' => 'fake-vm-floating',
        'metadata' => [],
    ]);

    /** @var ProviderNetwork $providerNetwork */
    $providerNetwork = ProviderNetwork::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Floating IP Binding Provider',
        'slug' => 'floating-binding-provider',
        'provider' => 'fake',
        'provider_cluster' => null,
        'network_type' => 'bridge',
        'external_id' => 'vmbr-floating-binding',
        'bridge' => 'vmbr-floating-binding',
        'vlan_tag' => null,
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    /** @var NetworkOffering $offering */
    $offering = NetworkOffering::query()->create([
        'tenant_id' => $tenant->getKey(),
        'provider_network_id' => $providerNetwork->getKey(),
        'name' => 'Floating IP Binding Offering',
        'slug' => 'floating-binding-offering',
        'offering_type' => 'provider-direct',
        'reachability' => 'routable_from_management',
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    /** @var DeploymentNetworkBinding $binding */
    $binding = DeploymentNetworkBinding::query()->create([
        'tenant_id' => $tenant->getKey(),
        'deployment_id' => $deployment->getKey(),
        'deployment_resource_id' => $resource->getKey(),
        'network_offering_id' => $offering->getKey(),
        'provider_network_id' => $providerNetwork->getKey(),
        'component_key' => 'vm',
        'nic_key' => 'public',
        'reachability' => 'routable_from_management',
        'state' => 'attached',
        'provider' => 'fake',
        'provider_binding' => ['provider' => 'fake', 'external_id' => 'vmbr-floating-binding'],
        'management_host' => null,
        'management_port' => null,
        'metadata' => [],
    ]);

    return $binding;
}

function createFloatingIpQuota(Tenant $tenant, Project $project, int $limitValue): QuotaLimit
{
    /** @var QuotaLimit $limit */
    $limit = QuotaLimit::query()->create([
        'tenant_id' => $tenant->getKey(),
        'scope_type' => 'project',
        'scope_id' => $project->getKey(),
        'dimension' => 'floating_ips',
        'limit_value' => $limitValue,
        'metadata' => [
            'source' => 'floating-ip-test',
        ],
    ]);

    return $limit;
}
