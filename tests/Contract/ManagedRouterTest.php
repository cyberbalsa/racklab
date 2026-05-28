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
use App\Models\QuotaLimit;
use App\Models\QuotaUsage;
use App\Models\RoleBinding;
use App\Models\Router;
use App\Models\RouterNetwork;
use App\Models\Subnet;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('lets an instructor create a router between two project networks with quota usage', function (): void {
    [$tenant, $user, $project] = provisionManagedRouterProject();
    $offering = createManagedRouterOffering($tenant);
    $left = createManagedRouterNetwork($tenant, $project, $offering, 'left-net', '10.90.0.0/24');
    $right = createManagedRouterNetwork($tenant, $project, $offering, 'right-net', '10.91.0.0/24');
    createManagedRouterQuota($tenant, $project, 1);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/routers', [
        'project_id' => $project->getKey(),
        'name' => 'Lab Router',
        'slug' => 'lab-router',
        'network_ids' => [$left->getKey(), $right->getKey()],
    ])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'lab-router')
        ->assertJsonPath('data.state', 'active')
        ->assertJsonCount(2, 'data.interfaces');

    expect(Router::query()->where('slug', 'lab-router')->exists())->toBeTrue()
        ->and(RouterNetwork::query()->count())->toBe(2)
        ->and(QuotaUsage::query()->where('dimension', 'routers')->where('state', 'active')->count())->toBe(1)
        ->and(AuditEvent::query()->where('event_type', 'network.router')->where('result', 'allowed')->exists())->toBeTrue();
});

it('denies router creation without the router permission and audits the denial', function (): void {
    [$tenant, $user, $project] = provisionManagedRouterProject();
    $offering = createManagedRouterOffering($tenant);
    $left = createManagedRouterNetwork($tenant, $project, $offering, 'left-net', '10.92.0.0/24');
    $right = createManagedRouterNetwork($tenant, $project, $offering, 'right-net', '10.93.0.0/24');

    RoleBinding::query()
        ->where('principal_type', 'user')
        ->where('principal_id', (string) $user->id)
        ->delete();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/routers', [
        'project_id' => $project->getKey(),
        'name' => 'Denied Router',
        'slug' => 'denied-router',
        'network_ids' => [$left->getKey(), $right->getKey()],
    ])->assertForbidden();

    expect(Router::query()->count())->toBe(0)
        ->and(RouterNetwork::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', 'network.router')->where('result', 'denied')->exists())->toBeTrue();
});

it('denies router creation when router quota is exhausted', function (): void {
    [$tenant, $user, $project] = provisionManagedRouterProject();
    $offering = createManagedRouterOffering($tenant);
    $left = createManagedRouterNetwork($tenant, $project, $offering, 'left-net', '10.94.0.0/24');
    $right = createManagedRouterNetwork($tenant, $project, $offering, 'right-net', '10.95.0.0/24');
    createManagedRouterQuota($tenant, $project, 0);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/routers', [
        'project_id' => $project->getKey(),
        'name' => 'Quota Denied Router',
        'slug' => 'quota-denied-router',
        'network_ids' => [$left->getKey(), $right->getKey()],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quota');

    expect(Router::query()->count())->toBe(0)
        ->and(RouterNetwork::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', 'quota.denied')->where('action', 'network.router.create')->exists())->toBeTrue();
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionManagedRouterProject(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create([
        'name' => 'Managed Router Instructor',
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

function createManagedRouterOffering(Tenant $tenant): NetworkOffering
{
    /** @var ProviderNetwork $providerNetwork */
    $providerNetwork = ProviderNetwork::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Router Provider Network',
        'slug' => 'router-provider-network',
        'provider' => 'fake',
        'provider_cluster' => null,
        'network_type' => 'bridge',
        'external_id' => 'vmbr-router',
        'bridge' => 'vmbr-router',
        'vlan_tag' => null,
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    /** @var NetworkOffering $offering */
    $offering = NetworkOffering::query()->create([
        'tenant_id' => $tenant->getKey(),
        'provider_network_id' => $providerNetwork->getKey(),
        'name' => 'Router Isolated Offering',
        'slug' => 'router-isolated-offering',
        'offering_type' => 'private-isolated',
        'reachability' => 'isolated_no_ingress',
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    return $offering;
}

function createManagedRouterNetwork(
    Tenant $tenant,
    Project $project,
    NetworkOffering $offering,
    string $slug,
    string $cidr,
): Network {
    /** @var Network $network */
    $network = Network::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'network_offering_id' => $offering->getKey(),
        'name' => ucfirst($slug),
        'slug' => $slug,
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
        'cidr' => $cidr,
        'ip_version' => 4,
        'gateway_ip' => null,
        'dhcp_enabled' => true,
        'allocation_pools' => [],
        'dns_nameservers' => [],
        'host_routes' => [],
        'metadata' => [],
    ]);

    return $network;
}

function createManagedRouterQuota(Tenant $tenant, Project $project, int $limitValue): QuotaLimit
{
    /** @var QuotaLimit $limit */
    $limit = QuotaLimit::query()->create([
        'tenant_id' => $tenant->getKey(),
        'scope_type' => 'project',
        'scope_id' => $project->getKey(),
        'dimension' => 'routers',
        'limit_value' => $limitValue,
        'metadata' => [
            'source' => 'managed-router-test',
        ],
    ]);

    return $limit;
}
