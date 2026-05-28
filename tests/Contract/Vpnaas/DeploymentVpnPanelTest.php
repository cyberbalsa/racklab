<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Livewire\Vpnaas\DeploymentVpnPanel;
use App\Models\Deployment;
use App\Models\Network;
use App\Models\NetworkOffering;
use App\Models\NetworkVpnEndpoint;
use App\Models\ProviderNetwork;
use App\Models\QuotaLimit;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VpnPublicIpPool;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders an empty-state message when the deployment has no VPN endpoints', function (): void {
    [, $user, $deployment] = provisionDeploymentVpnPanelFixture(attachEndpoint: false);

    actingAsPanelUser($user);
    Livewire::test(DeploymentVpnPanel::class, ['deployment' => $deployment])
        ->assertOk()
        ->assertSee('data-testid="deployment-vpn-panel-empty"', escape: false)
        ->assertDontSee('data-testid="vpn-endpoint-row"', escape: false);
});

it('renders one row per VPN endpoint with the binding public_ip:udp_port', function (): void {
    [, $user, $deployment, $endpoint] = provisionDeploymentVpnPanelFixture();

    actingAsPanelUser($user);
    Livewire::test(DeploymentVpnPanel::class, ['deployment' => $deployment])
        ->assertOk()
        ->assertSee('data-testid="vpn-endpoint-row"', escape: false)
        ->assertSee('data-endpoint-id="'.$endpoint->getKey().'"', escape: false)
        ->assertSee('203.0.113.')
        ->assertSee('data-testid="vpn-profile-missing"', escape: false);
});

it('shows the active-profile indicator after the owner has a profile issued', function (): void {
    [, $user, $deployment, $endpoint] = provisionDeploymentVpnPanelFixture();

    Sanctum::actingAs($user);
    test()->postJson('/api/v1/vpn-client-profiles', [
        'network_vpn_endpoint_id' => $endpoint->getKey(),
    ])->assertCreated();
    auth()->forgetGuards();

    actingAsPanelUser($user);
    Livewire::test(DeploymentVpnPanel::class, ['deployment' => $deployment])
        ->assertOk()
        ->assertSee('data-testid="vpn-profile-status"', escape: false)
        ->assertSee('data-profile-state="active"', escape: false)
        ->assertDontSee('data-testid="vpn-profile-missing"', escape: false);
});

it('refuses to render endpoints for a deployment the actor cannot read', function (): void {
    [$tenant, , $deployment] = provisionDeploymentVpnPanelFixture();

    // Provision an outsider in the same tenant who has no role binding on the deployment.
    $outsider = User::factory()->create(['name' => 'Panel Outsider', 'email' => 'panel-outsider@example.test']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    app(PersonalProjectProvisioner::class)->ensureFor($outsider, $context);
    App\Models\RoleBinding::query()
        ->where('principal_type', 'user')
        ->where('principal_id', (string) $outsider->getKey())
        ->delete();

    Sanctum::actingAs($outsider);
    Livewire::test(DeploymentVpnPanel::class, ['deployment' => $deployment])
        ->assertOk()
        ->assertSee('data-testid="deployment-vpn-panel-empty"', escape: false)
        ->assertDontSee('data-testid="vpn-endpoint-row"', escape: false);
});

it('marks deploymentId as Locked so the browser cannot swap deployments mid-session', function (): void {
    $reflection = new ReflectionProperty(DeploymentVpnPanel::class, 'deploymentId');
    $hasLocked = false;

    foreach ($reflection->getAttributes() as $attribute) {
        if ($attribute->getName() === \Livewire\Attributes\Locked::class) {
            $hasLocked = true;
        }
    }

    expect($hasLocked)->toBeTrue();
});

function actingAsPanelUser(User $user): void
{
    /** @var Tenant $tenant */
    $tenant = Tenant::query()->where('slug', 'default')->firstOrFail();
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    Sanctum::actingAs($user);
}

/**
 * @return array{Tenant, User, Deployment, NetworkVpnEndpoint|null}
 */
function provisionDeploymentVpnPanelFixture(bool $attachEndpoint = true): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(['slug' => 'default'], ['name' => 'Default Tenant']);
    $user = User::factory()->create(['name' => 'Panel Owner', 'email' => 'panel-owner@example.test']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    Sanctum::actingAs($user);
    $deploymentId = test()->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'vpn-panel-deploy',
    ])->assertCreated()->json('data.id');
    auth()->forgetGuards();

    /** @var Deployment $deployment */
    $deployment = Deployment::query()->whereKey($deploymentId)->firstOrFail();

    if (! $attachEndpoint) {
        return [$tenant, $user, $deployment, null];
    }

    /** @var ProviderNetwork $providerNetwork */
    $providerNetwork = ProviderNetwork::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Panel Provider',
        'slug' => 'panel-provider',
        'provider' => 'fake',
        'provider_cluster' => null,
        'network_type' => 'bridge',
        'external_id' => 'vmbr-panel',
        'bridge' => 'vmbr-panel',
        'vlan_tag' => null,
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);
    /** @var NetworkOffering $offering */
    $offering = NetworkOffering::query()->create([
        'tenant_id' => $tenant->getKey(),
        'provider_network_id' => $providerNetwork->getKey(),
        'name' => 'Panel Isolated',
        'slug' => 'panel-isolated',
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
        'name' => 'Panel Network',
        'slug' => 'panel-network',
        'state' => 'active',
        'provider' => 'fake',
        'reachability' => 'isolated_no_ingress',
        'provider_binding' => [],
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);
    /** @var VpnPublicIpPool $pool */
    $pool = VpnPublicIpPool::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Panel Pool',
        'slug' => 'panel-pool',
        'provider' => 'openvpn',
        'cidr' => '203.0.113.32/29',
        'port_range_min' => 23000,
        'port_range_max' => 23099,
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    foreach (['vpnaas_endpoints', 'vpnaas_endpoint_public_ips', 'vpnaas_endpoint_ports', 'vpnaas_client_profiles'] as $dim) {
        QuotaLimit::query()->create([
            'tenant_id' => $tenant->getKey(),
            'scope_type' => 'project',
            'scope_id' => $project->getKey(),
            'dimension' => $dim,
            'limit_value' => 4,
            'metadata' => ['source' => 'panel-test'],
        ]);
    }

    Sanctum::actingAs($user);
    $endpointId = test()->postJson('/api/v1/network-vpn-endpoints', [
        'name' => 'panel-vpn',
        'project_id' => $project->getKey(),
        'network_id' => $network->getKey(),
        'vpn_public_ip_pool_slug' => $pool->slug,
        'deployment_id' => $deployment->getKey(),
    ])->assertCreated()->json('data.id');
    auth()->forgetGuards();

    /** @var NetworkVpnEndpoint $endpoint */
    $endpoint = NetworkVpnEndpoint::query()->with('bindings')->whereKey($endpointId)->firstOrFail();

    return [$tenant, $user, $deployment, $endpoint];
}
