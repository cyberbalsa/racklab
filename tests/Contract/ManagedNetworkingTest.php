<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\AuditEvent;
use App\Models\Network;
use App\Models\NetworkOffering;
use App\Models\Project;
use App\Models\ProviderNetwork;
use App\Models\QuotaEvent;
use App\Models\QuotaLimit;
use App\Models\QuotaUsage;
use App\Models\RoleBinding;
use App\Models\Subnet;
use App\Models\SubnetPool;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('lets a student create a project network and subnet from a private NAT offering with quota usage', function (): void {
    [$tenant, $user, $project] = provisionManagedNetworkingProject();
    $offering = createManagedNetworkOffering($tenant, 'private-nat');
    createManagedNetworkQuota($tenant, $project, 1);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/networks', [
        'project_id' => $project->getKey(),
        'network_offering_id' => $offering->getKey(),
        'name' => 'Student NAT Network',
        'slug' => 'student-nat',
        'subnet' => [
            'cidr' => '10.42.0.0/24',
            'gateway_ip' => '10.42.0.1',
            'dhcp_enabled' => true,
            'dns_nameservers' => ['1.1.1.1'],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'student-nat')
        ->assertJsonPath('data.offering_slug', 'private-nat')
        ->assertJsonPath('data.subnets.0.cidr', '10.42.0.0/24');

    expect(Network::query()->where('slug', 'student-nat')->exists())->toBeTrue()
        ->and(Subnet::query()->where('cidr', '10.42.0.0/24')->exists())->toBeTrue()
        ->and(QuotaUsage::query()->where('dimension', 'private_networks')->where('state', 'active')->count())->toBe(1)
        ->and(QuotaEvent::query()->where('event_type', 'quota.consumed')->where('dimension', 'private_networks')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', 'network.create')->where('result', 'allowed')->exists())->toBeTrue();
});

it('denies project network creation without the required network permission and audits the denial', function (): void {
    [$tenant, $user, $project] = provisionManagedNetworkingProject();
    $offering = createManagedNetworkOffering($tenant, 'private-isolated');

    RoleBinding::query()
        ->where('principal_type', 'user')
        ->where('principal_id', (string) $user->id)
        ->delete();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/networks', [
        'project_id' => $project->getKey(),
        'network_offering_id' => $offering->getKey(),
        'name' => 'Denied Network',
        'slug' => 'denied-network',
        'subnet' => [
            'cidr' => '10.43.0.0/24',
        ],
    ])->assertForbidden();

    expect(Network::query()->count())->toBe(0)
        ->and(Subnet::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', 'network.create')->where('result', 'denied')->exists())->toBeTrue();
});

it('denies project network creation when private network quota is exhausted', function (): void {
    [$tenant, $user, $project] = provisionManagedNetworkingProject();
    $offering = createManagedNetworkOffering($tenant, 'private-isolated');
    createManagedNetworkQuota($tenant, $project, 0);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/networks', [
        'project_id' => $project->getKey(),
        'network_offering_id' => $offering->getKey(),
        'name' => 'Quota Denied Network',
        'slug' => 'quota-denied-network',
        'subnet' => [
            'cidr' => '10.44.0.0/24',
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quota');

    expect(Network::query()->count())->toBe(0)
        ->and(Subnet::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', 'quota.denied')->where('resource_type', 'project')->exists())->toBeTrue();
});

it('allocates the next available subnet from an approved subnet pool', function (): void {
    [$tenant, $user, $project] = provisionManagedNetworkingProject();
    $offering = createManagedNetworkOffering($tenant, 'private-isolated');
    $pool = createManagedSubnetPool($tenant, 'student-private-pool', '10.60.0.0/16', 24, 24, 28);
    createManagedNetworkQuota($tenant, $project, 3);
    createExistingManagedSubnet($tenant, $project, $offering, $pool, '10.60.0.0/24');

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/networks', [
        'project_id' => $project->getKey(),
        'network_offering_slug' => 'private-isolated',
        'name' => 'Pool Allocated Network',
        'slug' => 'pool-allocated-network',
        'subnet' => [
            'subnet_pool_id' => $pool->getKey(),
            'prefix_length' => 24,
            'gateway_ip' => '10.60.1.1',
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.subnets.0.cidr', '10.60.1.0/24');

    /** @var Subnet $subnet */
    $subnet = Subnet::query()->where('cidr', '10.60.1.0/24')->firstOrFail();

    expect($subnet->subnet_pool_id)->toBe($pool->getKey());
});

it('rejects subnet pool allocation when the requested prefix is outside policy', function (): void {
    [$tenant, $user, $project] = provisionManagedNetworkingProject();
    createManagedNetworkOffering($tenant, 'private-isolated');
    $pool = createManagedSubnetPool($tenant, 'strict-pool', '10.61.0.0/16', 24, 24, 24);
    createManagedNetworkQuota($tenant, $project, 1);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/networks', [
        'project_id' => $project->getKey(),
        'network_offering_slug' => 'private-isolated',
        'name' => 'Invalid Prefix Network',
        'slug' => 'invalid-prefix-network',
        'subnet' => [
            'subnet_pool_id' => $pool->getKey(),
            'prefix_length' => 25,
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('subnet.prefix_length');

    expect(Network::query()->count())->toBe(0)
        ->and(Subnet::query()->count())->toBe(0);
});

it('rejects subnet pool allocation when the pool has no free CIDR ranges', function (): void {
    [$tenant, $user, $project] = provisionManagedNetworkingProject();
    $offering = createManagedNetworkOffering($tenant, 'private-isolated');
    $pool = createManagedSubnetPool($tenant, 'tiny-pool', '10.62.0.0/30', 30, 30, 30);
    createManagedNetworkQuota($tenant, $project, 2);
    createExistingManagedSubnet($tenant, $project, $offering, $pool, '10.62.0.0/30');

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/networks', [
        'project_id' => $project->getKey(),
        'network_offering_slug' => 'private-isolated',
        'name' => 'Exhausted Pool Network',
        'slug' => 'exhausted-pool-network',
        'subnet' => [
            'subnet_pool_slug' => 'tiny-pool',
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('subnet.subnet_pool_id');

    expect(Network::query()->where('slug', 'exhausted-pool-network')->exists())->toBeFalse();
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionManagedNetworkingProject(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(
        ['slug' => 'default'],
        ['name' => 'Default Tenant'],
    );
    $user = User::factory()->create([
        'name' => 'Managed Network Student',
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

function createManagedNetworkOffering(Tenant $tenant, string $offeringType): NetworkOffering
{
    /** @var ProviderNetwork $providerNetwork */
    $providerNetwork = ProviderNetwork::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Managed Provider Network '.$offeringType,
        'slug' => 'managed-provider-'.$offeringType,
        'provider' => 'fake',
        'provider_cluster' => null,
        'network_type' => 'bridge',
        'external_id' => 'vmbr-'.$offeringType,
        'bridge' => 'vmbr-'.$offeringType,
        'vlan_tag' => null,
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    /** @var NetworkOffering $offering */
    $offering = NetworkOffering::query()->create([
        'tenant_id' => $tenant->getKey(),
        'provider_network_id' => $providerNetwork->getKey(),
        'name' => 'Managed Offering '.$offeringType,
        'slug' => $offeringType,
        'offering_type' => $offeringType,
        'reachability' => $offeringType === 'private-nat' ? 'nat_from_management' : 'isolated_no_ingress',
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    return $offering;
}

function createManagedNetworkQuota(Tenant $tenant, Project $project, int $limitValue): QuotaLimit
{
    /** @var QuotaLimit $limit */
    $limit = QuotaLimit::query()->create([
        'tenant_id' => $tenant->getKey(),
        'scope_type' => 'project',
        'scope_id' => $project->getKey(),
        'dimension' => 'private_networks',
        'limit_value' => $limitValue,
        'metadata' => [
            'source' => 'managed-networking-test',
        ],
    ]);

    return $limit;
}

function createManagedSubnetPool(
    Tenant $tenant,
    string $slug,
    string $cidr,
    int $defaultPrefixLength,
    int $minPrefixLength,
    int $maxPrefixLength,
): SubnetPool {
    /** @var SubnetPool $pool */
    $pool = SubnetPool::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Managed '.$slug,
        'slug' => $slug,
        'cidr' => $cidr,
        'ip_version' => 4,
        'default_prefix_length' => $defaultPrefixLength,
        'min_prefix_length' => $minPrefixLength,
        'max_prefix_length' => $maxPrefixLength,
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    return $pool;
}

function createExistingManagedSubnet(
    Tenant $tenant,
    Project $project,
    NetworkOffering $offering,
    SubnetPool $pool,
    string $cidr,
): void {
    /** @var Network $network */
    $network = Network::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'network_offering_id' => $offering->getKey(),
        'name' => 'Existing Pool Network',
        'slug' => 'existing-pool-network-'.str_replace(['.', '/'], '-', $cidr),
        'state' => 'active',
        'provider' => 'fake',
        'reachability' => $offering->reachability,
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    Subnet::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'network_id' => $network->getKey(),
        'subnet_pool_id' => $pool->getKey(),
        'cidr' => $cidr,
        'ip_version' => 4,
        'gateway_ip' => null,
        'dhcp_enabled' => true,
        'allocation_pools' => [],
        'dns_nameservers' => [],
        'host_routes' => [],
        'metadata' => [],
    ]);
}
