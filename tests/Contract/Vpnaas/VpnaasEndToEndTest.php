<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\AuditEvent;
use App\Models\Network;
use App\Models\NetworkOffering;
use App\Models\NetworkVpnEndpoint;
use App\Models\NetworkVpnEndpointBinding;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ProviderNetwork;
use App\Models\QuotaLimit;
use App\Models\RoleBinding;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VpnClientProfile;
use App\Models\VpnPublicIpPool;
use App\Models\VpnSession;
use App\Networking\VpnSessionService;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('walks the full M5c VPNaaS journey: endpoint + two profiles + selective revocation + session audit', function (): void {
    [$tenant, $owner, $second, $project, $endpoint] = provisionGroupVpnaasFixture();

    // 1. Owner downloads their own profile.
    Sanctum::actingAs($owner);
    $ownerProfileId = $this->postJson('/api/v1/vpn-client-profiles', [
        'network_vpn_endpoint_id' => $endpoint->getKey(),
    ])->assertCreated()->json('data.id');

    $download = $this->get('/api/v1/vpn-client-profiles/'.$ownerProfileId.'/download');
    $download->assertOk();
    expect($download->getContent())->toContain('-----BEGIN CERTIFICATE-----')
        ->and($download->getContent())->toContain('-----BEGIN PRIVATE KEY-----');
    auth()->forgetGuards();

    // 2. Second project member gets their own profile — group-project case.
    Sanctum::actingAs($second);
    $secondProfileId = $this->postJson('/api/v1/vpn-client-profiles', [
        'network_vpn_endpoint_id' => $endpoint->getKey(),
    ])->assertCreated()->json('data.id');

    expect(VpnClientProfile::query()->whereKey($ownerProfileId)->firstOrFail()->user_id)->toBe($owner->id)
        ->and(VpnClientProfile::query()->whereKey($secondProfileId)->firstOrFail()->user_id)->toBe($second->id);

    // 3. Second user cannot download the first user's profile (owner-only).
    $this->get('/api/v1/vpn-client-profiles/'.$ownerProfileId.'/download')->assertForbidden();
    auth()->forgetGuards();

    // 4. Each profile has its own session ledger; sessions are scoped.
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    /** @var VpnClientProfile $ownerProfile */
    $ownerProfile = VpnClientProfile::query()->whereKey($ownerProfileId)->firstOrFail();
    /** @var VpnClientProfile $secondProfile */
    $secondProfile = VpnClientProfile::query()->whereKey($secondProfileId)->firstOrFail();

    $ownerSession = app(VpnSessionService::class)->recordConnect($owner, $context, $ownerProfile, '198.51.100.10');
    $secondSession = app(VpnSessionService::class)->recordConnect($second, $context, $secondProfile, '198.51.100.11');
    expect($ownerSession->vpn_client_profile_id)->toBe($ownerProfile->getKey())
        ->and($secondSession->vpn_client_profile_id)->toBe($secondProfile->getKey());

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    // 5. Owner revokes the second user's profile (admin action). Other
    //    profile keeps working. Sessions close for the revoked one.
    Sanctum::actingAs($owner);
    $this->postJson('/api/v1/vpn-client-profiles/'.$secondProfileId.'/revoke', ['reason' => 'admin_test'])
        ->assertOk()
        ->assertJsonPath('data.state', 'revoked');
    auth()->forgetGuards();

    expect(VpnClientProfile::query()->whereKey($ownerProfileId)->firstOrFail()->state)->toBe(VpnClientProfile::STATE_ACTIVE)
        ->and(VpnClientProfile::query()->whereKey($secondProfileId)->firstOrFail()->state)->toBe(VpnClientProfile::STATE_REVOKED);
    expect(VpnSession::query()->where('vpn_client_profile_id', $secondProfileId)->where('state', VpnSession::STATE_CLOSED)->exists())
        ->toBeTrue();
    expect(VpnSession::query()->where('vpn_client_profile_id', $ownerProfileId)->where('state', VpnSession::STATE_ACTIVE)->exists())
        ->toBeTrue();

    // 6. Owner can still download — second cannot.
    Sanctum::actingAs($owner);
    $this->get('/api/v1/vpn-client-profiles/'.$ownerProfileId.'/download')->assertOk();
    auth()->forgetGuards();

    Sanctum::actingAs($second);
    $this->get('/api/v1/vpn-client-profiles/'.$secondProfileId.'/download')->assertStatus(422);
    auth()->forgetGuards();

    // 7. Endpoint release converges remaining profiles to revoked and closes
    //    bindings, freeing all quota.
    Sanctum::actingAs($owner);
    $this->deleteJson('/api/v1/network-vpn-endpoints/'.$endpoint->getKey())->assertNoContent();
    auth()->forgetGuards();

    expect(NetworkVpnEndpoint::query()->whereKey($endpoint->getKey())->firstOrFail()->state)
        ->toBe(NetworkVpnEndpoint::STATE_RELEASED);
    expect(VpnClientProfile::query()->whereKey($ownerProfileId)->firstOrFail()->state)
        ->toBe(VpnClientProfile::STATE_REVOKED);
    expect(NetworkVpnEndpointBinding::query()->where('network_vpn_endpoint_id', $endpoint->getKey())->where('state', NetworkVpnEndpointBinding::STATE_RELEASED)->exists())
        ->toBeTrue();

    // 8. Audit trail: every lifecycle event is recorded.
    foreach (['issue', 'revoke', 'session_connect', 'session_disconnect'] as $action) {
        expect(AuditEvent::query()
            ->where('event_type', 'network.vpnaas.profile')
            ->where('action', $action)
            ->exists()
        )->toBeTrue('audit missing for network.vpnaas.profile action '.$action);
    }

    expect(AuditEvent::query()->where('event_type', 'network.vpnaas.endpoint')->where('action', 'release')->where('result', 'allowed')->exists())->toBeTrue();
});

/**
 * @return array{Tenant, User, User, Project, NetworkVpnEndpoint}
 */
function provisionGroupVpnaasFixture(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();
    enableVpnaasPluginForTests();

    $tenant = Tenant::query()->firstOrCreate(['slug' => 'default'], ['name' => 'Default Tenant']);
    $owner = User::factory()->create(['name' => 'Group Owner', 'email' => 'group-owner@example.test']);
    $second = User::factory()->create(['name' => 'Group Member', 'email' => 'group-member@example.test']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($owner, $context);
    app(PersonalProjectProvisioner::class)->ensureFor($second, $context);

    // Add second user to the owner's project so cross-user VPN profile issuance works.
    ProjectMembership::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'user_id' => $second->getKey(),
        'role' => 'member',
    ]);
    // Give the second user a project-scoped student binding so AccessResolver allows them.
    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $second->getKey(),
        'role' => 'student',
        'resource_type' => $project->resourceType(),
        'resource_id' => $project->resourceId(),
        'scope_type' => 'tenant_local',
        'tenant_id' => $tenant->getKey(),
        'tenant_set' => [$tenant->getKey()],
        'granted_by_id' => $owner->getKey(),
        'granted_reason' => 'group project member',
    ]);

    /** @var ProviderNetwork $providerNetwork */
    $providerNetwork = ProviderNetwork::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'E2E Provider', 'slug' => 'e2e-provider', 'provider' => 'fake',
        'provider_cluster' => null, 'network_type' => 'bridge',
        'external_id' => 'vmbr-e2e', 'bridge' => 'vmbr-e2e', 'vlan_tag' => null,
        'metadata' => [], 'sharing_scope' => 'tenant_local', 'shared_with_tenants' => [],
    ]);
    /** @var NetworkOffering $offering */
    $offering = NetworkOffering::query()->create([
        'tenant_id' => $tenant->getKey(), 'provider_network_id' => $providerNetwork->getKey(),
        'name' => 'E2E Isolated', 'slug' => 'e2e-isolated', 'offering_type' => 'isolated',
        'reachability' => 'isolated_no_ingress',
        'provider_binding' => [], 'metadata' => [],
        'sharing_scope' => 'tenant_local', 'shared_with_tenants' => [],
    ]);
    /** @var Network $network */
    $network = Network::query()->create([
        'tenant_id' => $tenant->getKey(), 'project_id' => $project->getKey(),
        'network_offering_id' => $offering->getKey(),
        'name' => 'E2E Network', 'slug' => 'e2e-network',
        'state' => 'active', 'provider' => 'fake',
        'reachability' => 'isolated_no_ingress',
        'provider_binding' => [], 'metadata' => [],
        'sharing_scope' => 'tenant_local', 'shared_with_tenants' => [],
    ]);
    /** @var VpnPublicIpPool $pool */
    $pool = VpnPublicIpPool::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'E2E Pool', 'slug' => 'e2e-pool',
        'provider' => 'openvpn', 'cidr' => '203.0.113.48/29',
        'port_range_min' => 24000, 'port_range_max' => 24099,
        'metadata' => [], 'sharing_scope' => 'tenant_local', 'shared_with_tenants' => [],
    ]);

    foreach (['vpnaas_endpoints', 'vpnaas_endpoint_public_ips', 'vpnaas_endpoint_ports', 'vpnaas_client_profiles'] as $dim) {
        QuotaLimit::query()->create([
            'tenant_id' => $tenant->getKey(),
            'scope_type' => 'project', 'scope_id' => $project->getKey(),
            'dimension' => $dim, 'limit_value' => 8,
            'metadata' => ['source' => 'e2e-test'],
        ]);
    }

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    Sanctum::actingAs($owner);
    $endpointId = test()->postJson('/api/v1/network-vpn-endpoints', [
        'name' => 'group-vpn',
        'project_id' => $project->getKey(),
        'network_id' => $network->getKey(),
        'vpn_public_ip_pool_slug' => $pool->slug,
    ])->assertCreated()->json('data.id');
    auth()->forgetGuards();

    /** @var NetworkVpnEndpoint $endpoint */
    $endpoint = NetworkVpnEndpoint::query()->whereKey($endpointId)->firstOrFail();

    return [$tenant, $owner, $second, $project, $endpoint];
}
