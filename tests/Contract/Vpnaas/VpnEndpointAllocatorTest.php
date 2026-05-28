<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\NetworkVpnEndpoint;
use App\Models\NetworkVpnEndpointBinding;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VpnPublicIpPool;
use App\Networking\VpnEndpointAllocator;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('places a binding with a public IP from the pool CIDR and a port in the configured range', function (): void {
    [$tenant] = provisionAllocatorFixture();
    $pool = createAllocatorPool($tenant, '203.0.113.0/29', 20100, 20109);
    $endpoint = createAllocatorEndpoint($tenant, $pool);

    $binding = app(VpnEndpointAllocator::class)->allocate($endpoint);

    expect($binding->public_ip)->toStartWith('203.0.113.')
        ->and($binding->udp_port)->toBeGreaterThanOrEqual(20100)
        ->and($binding->udp_port)->toBeLessThanOrEqual(20109)
        ->and($binding->state)->toBe(NetworkVpnEndpointBinding::STATE_ACTIVE);
});

it('allocates two endpoints distinct (public_ip, udp_port) pairs', function (): void {
    [$tenant] = provisionAllocatorFixture();
    $pool = createAllocatorPool($tenant, '203.0.113.0/30', 20200, 20209);
    $endpointA = createAllocatorEndpoint($tenant, $pool);
    $endpointB = createAllocatorEndpoint($tenant, $pool);

    $allocator = app(VpnEndpointAllocator::class);
    $bindingA = $allocator->allocate($endpointA);
    $bindingB = $allocator->allocate($endpointB);

    // Either different IP, or same IP with different port — the unique
    // (public_ip, udp_port) DB constraint is the bedrock guarantee.
    expect($bindingA->public_ip.':'.$bindingA->udp_port)
        ->not->toBe($bindingB->public_ip.':'.$bindingB->udp_port);
});

it('reuses a single-IP pool by picking different ports up to the range size', function (): void {
    [$tenant] = provisionAllocatorFixture();
    $pool = createAllocatorPool($tenant, '203.0.113.7/32', 20300, 20303); // 1 IP, 4 ports
    $allocator = app(VpnEndpointAllocator::class);

    $bindings = [];
    for ($i = 0; $i < 4; $i++) {
        $endpoint = createAllocatorEndpoint($tenant, $pool);
        $bindings[] = $allocator->allocate($endpoint);
    }

    $pairs = array_map(static fn (NetworkVpnEndpointBinding $b): string => $b->public_ip.':'.$b->udp_port, $bindings);
    expect(array_unique($pairs))->toHaveCount(4);
    foreach ($bindings as $binding) {
        expect($binding->public_ip)->toBe('203.0.113.7')
            ->and($binding->udp_port)->toBeGreaterThanOrEqual(20300)
            ->and($binding->udp_port)->toBeLessThanOrEqual(20303);
    }
});

it('refuses allocation when the pool is saturated', function (): void {
    [$tenant] = provisionAllocatorFixture();
    $pool = createAllocatorPool($tenant, '203.0.113.7/32', 20400, 20401); // 1 IP, 2 ports
    $allocator = app(VpnEndpointAllocator::class);

    $allocator->allocate(createAllocatorEndpoint($tenant, $pool));
    $allocator->allocate(createAllocatorEndpoint($tenant, $pool));

    expect(fn () => $allocator->allocate(createAllocatorEndpoint($tenant, $pool)))
        ->toThrow(ValidationException::class);
});

it('skips IPs whose bindings are only soft-released until they are hard-cleaned', function (): void {
    [$tenant] = provisionAllocatorFixture();
    // /30 covers .0-.3; .1 + .2 are usable. Port range size 1 → each IP holds at most one active binding.
    $pool = createAllocatorPool($tenant, '203.0.113.0/30', 20600, 20600);
    $allocator = app(VpnEndpointAllocator::class);

    // Allocate on .1, soft-release it (state=released but row remains).
    $endpointA = createAllocatorEndpoint($tenant, $pool);
    $bindingA = $allocator->allocate($endpointA);
    expect($bindingA->public_ip)->toBe('203.0.113.1');
    $bindingA->forceFill(['state' => NetworkVpnEndpointBinding::STATE_RELEASED])->save();

    // Next allocation must move past .1 even though its binding is soft-released:
    // the unique (public_ip, udp_port) constraint still holds the row.
    $endpointB = createAllocatorEndpoint($tenant, $pool);
    $bindingB = $allocator->allocate($endpointB);
    expect($bindingB->public_ip)->toBe('203.0.113.2');
});

it('finds a single free port deterministically when only one slot remains', function (): void {
    [$tenant] = provisionAllocatorFixture();
    $pool = createAllocatorPool($tenant, '203.0.113.7/32', 20700, 20705); // 6 ports total
    $allocator = app(VpnEndpointAllocator::class);

    // Allocate 5 bindings → 5 of 6 ports used.
    $taken = [];
    for ($i = 0; $i < 5; $i++) {
        $endpoint = createAllocatorEndpoint($tenant, $pool);
        $binding = $allocator->allocate($endpoint);
        $taken[] = $binding->udp_port;
    }

    expect(count(array_unique($taken)))->toBe(5);

    // The remaining free port must be located even though there's only one.
    // (Codex M5c S3 P2-3: random retry would probabilistically miss it.)
    $endpoint = createAllocatorEndpoint($tenant, $pool);
    $binding = $allocator->allocate($endpoint);
    $remaining = array_values(array_diff([20700, 20701, 20702, 20703, 20704, 20705], $taken));
    expect($binding->udp_port)->toBe($remaining[0]);
});

it('reuses freed (ip, port) slots after a binding is hard-cleaned', function (): void {
    [$tenant] = provisionAllocatorFixture();
    $pool = createAllocatorPool($tenant, '203.0.113.7/32', 20500, 20501); // 1 IP, 2 ports
    $allocator = app(VpnEndpointAllocator::class);

    $endpointA = createAllocatorEndpoint($tenant, $pool);
    $bindingA = $allocator->allocate($endpointA);
    $endpointB = createAllocatorEndpoint($tenant, $pool);
    $allocator->allocate($endpointB);

    // Released bindings keep the (public_ip, udp_port) pair locked under the unique
    // constraint, so quota recovery is a two-step dance: flip state to released
    // first (preserves the audit context the operator sees), then the cleanup job
    // deletes the row once the provider has torn the binding down. S3 only tests
    // the post-cleanup reuse; S6's release-reaper job is what hard-deletes.
    $bindingA->forceFill(['state' => NetworkVpnEndpointBinding::STATE_RELEASED])->save();
    $bindingA->delete();

    $endpointC = createAllocatorEndpoint($tenant, $pool);
    $bindingC = $allocator->allocate($endpointC);

    expect($bindingC->public_ip)->toBe('203.0.113.7')
        ->and($bindingC->state)->toBe(NetworkVpnEndpointBinding::STATE_ACTIVE);
});

/**
 * @return array{Tenant, User}
 */
function provisionAllocatorFixture(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(['slug' => 'default'], ['name' => 'Default Tenant']);
    $user = User::factory()->create([
        'name' => 'Allocator Owner',
        'email' => 'allocator-owner@example.test',
    ]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user];
}

function createAllocatorPool(Tenant $tenant, string $cidr, int $portMin, int $portMax): VpnPublicIpPool
{
    /** @var VpnPublicIpPool $pool */
    $pool = VpnPublicIpPool::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Allocator Pool '.$cidr,
        'slug' => 'allocator-'.str_replace(['/', '.'], '-', $cidr).'-'.$portMin,
        'provider' => 'openvpn',
        'cidr' => $cidr,
        'port_range_min' => $portMin,
        'port_range_max' => $portMax,
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    return $pool;
}

function createAllocatorEndpoint(Tenant $tenant, VpnPublicIpPool $pool): NetworkVpnEndpoint
{
    $project = App\Models\Project::query()->where('tenant_id', $tenant->getKey())->firstOrFail();
    $network = allocatorNetworkFor($tenant, $project);

    /** @var NetworkVpnEndpoint $endpoint */
    $endpoint = NetworkVpnEndpoint::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'deployment_id' => null,
        'network_id' => $network->getKey(),
        'vpn_public_ip_pool_id' => $pool->getKey(),
        'name' => 'allocator-endpoint-'.bin2hex(random_bytes(4)),
        'state' => NetworkVpnEndpoint::STATE_PENDING,
        'provider' => $pool->provider,
        'capability' => 'network:vpnaas:openvpn:v1',
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    return $endpoint;
}

function allocatorNetworkFor(Tenant $tenant, App\Models\Project $project): App\Models\Network
{
    /** @var App\Models\Network|null $existing */
    $existing = App\Models\Network::query()
        ->where('tenant_id', $tenant->getKey())
        ->where('project_id', $project->getKey())
        ->first();

    if ($existing instanceof App\Models\Network) {
        return $existing;
    }

    /** @var App\Models\ProviderNetwork $providerNetwork */
    $providerNetwork = App\Models\ProviderNetwork::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Allocator Provider Network',
        'slug' => 'allocator-provider-network',
        'provider' => 'fake',
        'provider_cluster' => null,
        'network_type' => 'bridge',
        'external_id' => 'vmbr-alloc',
        'bridge' => 'vmbr-alloc',
        'vlan_tag' => null,
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    /** @var App\Models\NetworkOffering $offering */
    $offering = App\Models\NetworkOffering::query()->create([
        'tenant_id' => $tenant->getKey(),
        'provider_network_id' => $providerNetwork->getKey(),
        'name' => 'Allocator Isolated Offering',
        'slug' => 'allocator-isolated',
        'offering_type' => 'isolated',
        'reachability' => 'isolated_no_ingress',
        'provider_binding' => [],
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    /** @var App\Models\Network $network */
    $network = App\Models\Network::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'network_offering_id' => $offering->getKey(),
        'name' => 'Allocator Test Network',
        'slug' => 'allocator-test-network',
        'state' => 'active',
        'provider' => 'fake',
        'reachability' => 'isolated_no_ingress',
        'provider_binding' => [],
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    return $network;
}
