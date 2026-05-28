<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\AuditEvent;
use App\Models\Network;
use App\Models\NetworkOffering;
use App\Models\NetworkVpnEndpoint;
use App\Models\Project;
use App\Models\ProviderNetwork;
use App\Models\QuotaEvent;
use App\Models\QuotaLimit;
use App\Models\QuotaUsage;
use App\Models\RoleBinding;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VpnPublicIpPool;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('creates a pending VPN endpoint, consumes quota, and emits the audit row', function (): void {
    [$tenant, $user, $project, $network] = provisionVpnaasFixture();
    createVpnPublicIpPool($tenant, 'lab-vpn-pool');
    createVpnEndpointQuota($tenant, $project, 1);

    Sanctum::actingAs($user);

    $endpointId = $this->postJson('/api/v1/network-vpn-endpoints', [
        'name' => 'lab-vpn',
        'project_id' => $project->getKey(),
        'network_id' => $network->getKey(),
        'vpn_public_ip_pool_slug' => 'lab-vpn-pool',
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'pending')
        ->assertJsonPath('data.name', 'lab-vpn')
        ->assertJsonPath('data.capability', 'network:vpnaas:openvpn:v1')
        ->json('data.id');

    expect(NetworkVpnEndpoint::query()->whereKey($endpointId)->firstOrFail()->state)
        ->toBe(NetworkVpnEndpoint::STATE_PENDING);

    expect(QuotaUsage::query()
        ->where('dimension', 'vpnaas_endpoints')
        ->where('state', 'active')
        ->whereJsonContains('metadata->network_vpn_endpoint_id', $endpointId)
        ->exists()
    )->toBeTrue();

    expect(QuotaEvent::query()
        ->where('event_type', 'quota.consumed')
        ->where('dimension', 'vpnaas_endpoints')
        ->exists()
    )->toBeTrue();

    expect(AuditEvent::query()
        ->where('event_type', 'network.vpnaas.endpoint')
        ->where('action', 'create')
        ->where('result', 'allowed')
        ->exists()
    )->toBeTrue();
});

it('refuses VPN endpoint creation when the actor lacks the create permission and audits the denial', function (): void {
    [$tenant, $user, $project, $network] = provisionVpnaasFixture();
    createVpnPublicIpPool($tenant, 'denied-pool');

    RoleBinding::query()
        ->where('principal_type', 'user')
        ->where('principal_id', (string) $user->id)
        ->delete();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/network-vpn-endpoints', [
        'name' => 'denied-vpn',
        'project_id' => $project->getKey(),
        'network_id' => $network->getKey(),
        'vpn_public_ip_pool_slug' => 'denied-pool',
    ])->assertForbidden();

    expect(NetworkVpnEndpoint::query()->count())->toBe(0);
    expect(AuditEvent::query()
        ->where('event_type', 'network.vpnaas.endpoint')
        ->where('result', 'denied')
        ->exists()
    )->toBeTrue();
});

it('refuses VPN endpoint creation when the project quota is exhausted', function (): void {
    [$tenant, $user, $project, $network] = provisionVpnaasFixture();
    createVpnPublicIpPool($tenant, 'capped-pool');
    createVpnEndpointQuota($tenant, $project, 0);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/network-vpn-endpoints', [
        'name' => 'capped-vpn',
        'project_id' => $project->getKey(),
        'network_id' => $network->getKey(),
        'vpn_public_ip_pool_slug' => 'capped-pool',
    ])->assertStatus(422)->assertJsonPath('errors.quota.0', 'VPNaaS quota exceeded: vpnaas_endpoints limit 0, used 0, requested 1.');

    expect(QuotaEvent::query()->where('event_type', 'quota.denied')->where('dimension', 'vpnaas_endpoints')->exists())->toBeTrue();
});

it('releases a VPN endpoint, releases quota, and audits the release', function (): void {
    [$tenant, $user, $project, $network] = provisionVpnaasFixture();
    createVpnPublicIpPool($tenant, 'release-pool');
    createVpnEndpointQuota($tenant, $project, 1);

    Sanctum::actingAs($user);

    $endpointId = $this->postJson('/api/v1/network-vpn-endpoints', [
        'name' => 'release-vpn',
        'project_id' => $project->getKey(),
        'network_id' => $network->getKey(),
        'vpn_public_ip_pool_slug' => 'release-pool',
    ])->assertCreated()->json('data.id');

    $this->deleteJson('/api/v1/network-vpn-endpoints/'.$endpointId)->assertNoContent();

    expect(NetworkVpnEndpoint::query()->whereKey($endpointId)->firstOrFail()->state)
        ->toBe(NetworkVpnEndpoint::STATE_RELEASED);

    expect(QuotaUsage::query()
        ->where('dimension', 'vpnaas_endpoints')
        ->where('state', 'released')
        ->whereJsonContains('metadata->network_vpn_endpoint_id', $endpointId)
        ->exists()
    )->toBeTrue();

    expect(AuditEvent::query()
        ->where('event_type', 'network.vpnaas.endpoint')
        ->where('action', 'release')
        ->where('result', 'allowed')
        ->exists()
    )->toBeTrue();

    // After release, quota recapacity allows a new endpoint to be created.
    $this->postJson('/api/v1/network-vpn-endpoints', [
        'name' => 'second-vpn',
        'project_id' => $project->getKey(),
        'network_id' => $network->getKey(),
        'vpn_public_ip_pool_slug' => 'release-pool',
    ])->assertCreated();
});

it('rejects endpoint creation when the network does not belong to the project', function (): void {
    [$tenant, $user, $project] = provisionVpnaasFixture();
    createVpnPublicIpPool($tenant, 'foreign-net-pool');

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/network-vpn-endpoints', [
        'name' => 'foreign-net',
        'project_id' => $project->getKey(),
        'network_id' => '01HZUNKNOWN0000000000000000',
        'vpn_public_ip_pool_slug' => 'foreign-net-pool',
    ])->assertStatus(422)->assertJsonValidationErrors('network_id');
});

it('rejects VPN endpoints on networks that are not isolated_no_ingress', function (): void {
    [$tenant, $user, $project, $network] = provisionVpnaasFixture();
    createVpnPublicIpPool($tenant, 'routable-pool');
    createVpnEndpointQuota($tenant, $project, 1);

    // Codex M5c S2 P1: VPN endpoints must reject routable / management-reachable
    // networks before realization to avoid bridging VPN clients onto management-
    // plane-reachable L2.
    $network->forceFill(['reachability' => 'routable_from_management'])->save();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/network-vpn-endpoints', [
        'name' => 'routable-vpn',
        'project_id' => $project->getKey(),
        'network_id' => $network->getKey(),
        'vpn_public_ip_pool_slug' => 'routable-pool',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('network_id');

    expect(NetworkVpnEndpoint::query()->count())->toBe(0);
});

it('rejects VPN endpoint requests that omit both pool id and slug', function (): void {
    [$tenant, $user, $project, $network] = provisionVpnaasFixture();
    createVpnPublicIpPool($tenant, 'unused-pool');

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/network-vpn-endpoints', [
        'name' => 'missing-pool',
        'project_id' => $project->getKey(),
        'network_id' => $network->getKey(),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['vpn_public_ip_pool_id', 'vpn_public_ip_pool_slug']);
});

/**
 * @return array{Tenant, User, Project, Network}
 */
function provisionVpnaasFixture(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(
        ['slug' => 'default'],
        ['name' => 'Default Tenant'],
    );
    $user = User::factory()->create([
        'name' => 'VPN Owner',
        'email' => 'vpn-owner@example.test',
    ]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    /** @var ProviderNetwork $providerNetwork */
    $providerNetwork = ProviderNetwork::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'VPN Provider Network',
        'slug' => 'vpn-provider-network',
        'provider' => 'fake',
        'provider_cluster' => null,
        'network_type' => 'bridge',
        'external_id' => 'vmbr-vpn',
        'bridge' => 'vmbr-vpn',
        'vlan_tag' => null,
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    /** @var NetworkOffering $offering */
    $offering = NetworkOffering::query()->create([
        'tenant_id' => $tenant->getKey(),
        'provider_network_id' => $providerNetwork->getKey(),
        'name' => 'VPN Isolated Offering',
        'slug' => 'vpn-isolated',
        'offering_type' => 'isolated',
        'reachability' => 'isolated_no_ingress',
        'provider_binding' => [],
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    /** @var Network $network */
    $network = Network::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'network_offering_id' => $offering->getKey(),
        'name' => 'VPN Test Network',
        'slug' => 'vpn-test-network',
        'state' => 'active',
        'provider' => 'fake',
        'reachability' => 'isolated_no_ingress',
        'provider_binding' => [],
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    // Subnet creation is unnecessary for the endpoint API; the Network row is the only
    // thing the controller resolves. The Subnet model is intentionally not used here.

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project, $network];
}

function createVpnPublicIpPool(Tenant $tenant, string $slug): VpnPublicIpPool
{
    /** @var VpnPublicIpPool $pool */
    $pool = VpnPublicIpPool::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Pool '.$slug,
        'slug' => $slug,
        'provider' => 'openvpn',
        'cidr' => '203.0.113.0/29',
        'port_range_min' => 20000,
        'port_range_max' => 20009,
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    return $pool;
}

function createVpnEndpointQuota(Tenant $tenant, Project $project, int $limitValue): QuotaLimit
{
    /** @var QuotaLimit $limit */
    $limit = QuotaLimit::query()->create([
        'tenant_id' => $tenant->getKey(),
        'scope_type' => 'project',
        'scope_id' => $project->getKey(),
        'dimension' => 'vpnaas_endpoints',
        'limit_value' => $limitValue,
        'metadata' => [
            'source' => 'vpnaas-test',
        ],
    ]);

    return $limit;
}
