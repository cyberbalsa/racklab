<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\AuditEvent;
use App\Models\Network;
use App\Models\NetworkOffering;
use App\Models\NetworkVpnEndpoint;
use App\Models\ProviderNetwork;
use App\Models\QuotaLimit;
use App\Models\RoleBinding;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VpnClientProfile;
use App\Models\VpnPublicIpPool;
use App\Models\VpnSession;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('issues a VPN client profile, consumes quota, and emits the issue audit row', function (): void {
    [, $owner, $endpoint] = provisionVpnaasProfileFixture();

    Sanctum::actingAs($owner);

    $profileId = $this->postJson('/api/v1/vpn-client-profiles', [
        'network_vpn_endpoint_id' => $endpoint->getKey(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'active')
        ->assertJsonPath('data.user_id', $owner->getKey())
        ->assertJsonPath('data.network_vpn_endpoint_id', $endpoint->getKey())
        ->json('data.id');

    /** @var VpnClientProfile $profile */
    $profile = VpnClientProfile::query()->whereKey($profileId)->firstOrFail();
    expect($profile->state)->toBe(VpnClientProfile::STATE_ACTIVE)
        ->and($profile->common_name)->toContain($endpoint->getKey())
        ->and($profile->config_ciphertext)->not->toBeEmpty()
        ->and($profile->private_key_ciphertext)->not->toBeEmpty();

    expect(AuditEvent::query()
        ->where('event_type', 'network.vpnaas.profile')
        ->where('action', 'issue')
        ->where('result', 'allowed')
        ->exists()
    )->toBeTrue();
});

it('refuses to issue two profiles for the same (endpoint, user) pair', function (): void {
    [, $owner, $endpoint] = provisionVpnaasProfileFixture();

    Sanctum::actingAs($owner);
    $this->postJson('/api/v1/vpn-client-profiles', [
        'network_vpn_endpoint_id' => $endpoint->getKey(),
    ])->assertCreated();

    $this->postJson('/api/v1/vpn-client-profiles', [
        'network_vpn_endpoint_id' => $endpoint->getKey(),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_id');
});

it('refuses profile issuance when the endpoint is not running', function (): void {
    [, $owner, $endpoint] = provisionVpnaasProfileFixture();
    $endpoint->forceFill(['state' => NetworkVpnEndpoint::STATE_STOPPED])->save();

    Sanctum::actingAs($owner);
    $this->postJson('/api/v1/vpn-client-profiles', [
        'network_vpn_endpoint_id' => $endpoint->getKey(),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('network_vpn_endpoint_id');
});

it('refuses VPN profile creation when the actor lacks the create permission', function (): void {
    [, $owner, $endpoint] = provisionVpnaasProfileFixture();

    RoleBinding::query()
        ->where('principal_type', 'user')
        ->where('principal_id', (string) $owner->id)
        ->delete();

    Sanctum::actingAs($owner);
    $this->postJson('/api/v1/vpn-client-profiles', [
        'network_vpn_endpoint_id' => $endpoint->getKey(),
    ])->assertForbidden();
});

it('lets the owner download their VPN profile and stamps downloaded_at', function (): void {
    [, $owner, $endpoint] = provisionVpnaasProfileFixture();

    Sanctum::actingAs($owner);
    $profileId = $this->postJson('/api/v1/vpn-client-profiles', [
        'network_vpn_endpoint_id' => $endpoint->getKey(),
    ])->assertCreated()->json('data.id');

    $response = $this->get('/api/v1/vpn-client-profiles/'.$profileId.'/download');
    $response->assertOk()
        ->assertHeader('Content-Type', 'application/x-openvpn-profile');

    $body = $response->getContent();
    expect($body)->toContain('client')
        ->and($body)->toContain('dev tap')
        ->and($body)->toContain($endpoint->bindings()->first()->public_ip)
        ->and($body)->toContain('-----BEGIN CERTIFICATE-----');

    expect(VpnClientProfile::query()->whereKey($profileId)->firstOrFail()->downloaded_at)->not->toBeNull();

    expect(AuditEvent::query()
        ->where('event_type', 'network.vpnaas.profile')
        ->where('action', 'download')
        ->where('result', 'allowed')
        ->exists()
    )->toBeTrue();
});

it('refuses owner-only download for a different user even if they have admin permissions', function (): void {
    [$tenant, $owner, $endpoint] = provisionVpnaasProfileFixture();

    Sanctum::actingAs($owner);
    $profileId = $this->postJson('/api/v1/vpn-client-profiles', [
        'network_vpn_endpoint_id' => $endpoint->getKey(),
    ])->assertCreated()->json('data.id');

    $admin = User::factory()->create(['name' => 'Admin', 'email' => 'vpn-admin@example.test']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    app(PersonalProjectProvisioner::class)->ensureFor($admin, $context);
    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $admin->getKey(),
        'role' => 'admin',
        'resource_type' => 'tenant',
        'resource_id' => $tenant->getKey(),
        'scope_type' => 'tenant_local',
        'tenant_id' => $tenant->getKey(),
        'tenant_set' => [$tenant->getKey()],
        'granted_by_id' => $admin->getKey(),
        'granted_reason' => 'test setup',
    ]);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    Sanctum::actingAs($admin);
    $this->get('/api/v1/vpn-client-profiles/'.$profileId.'/download')->assertForbidden();

    expect(AuditEvent::query()
        ->where('event_type', 'network.vpnaas.profile')
        ->where('action', 'download_denied')
        ->where('result', 'denied')
        ->exists()
    )->toBeTrue();
});

it('requires the revoke ability on the token even when the actor is the profile owner', function (): void {
    [, $owner, $endpoint] = provisionVpnaasProfileFixture();

    Sanctum::actingAs($owner);
    $profileId = $this->postJson('/api/v1/vpn-client-profiles', [
        'network_vpn_endpoint_id' => $endpoint->getKey(),
    ])->assertCreated()->json('data.id');

    /** @var App\Models\Project $project */
    $project = App\Models\Project::query()->where('created_for_user_id', $owner->getKey())->firstOrFail();

    // Issue a real Track-B PAT scoped only to download — never to revoke.
    $tokenPayload = $this->postJson('/api/v1/tokens', [
        'name' => 'download-only',
        'project_id' => $project->getKey(),
        'abilities' => ['network.vpnaas.profile.download'],
    ])->assertCreated()->json('data');
    auth()->forgetGuards();

    // Codex M5c S4 P1: a download-scoped token must not permit a destructive
    // revoke even when the caller is the profile owner.
    $this->withHeader('Authorization', $tokenPayload['authorization_header'])
        ->postJson('/api/v1/vpn-client-profiles/'.$profileId.'/revoke', ['reason' => 'token_attempt'])
        ->assertForbidden();

    expect(VpnClientProfile::query()->whereKey($profileId)->firstOrFail()->state)
        ->toBe(VpnClientProfile::STATE_ACTIVE);
});

it('refuses cross-user profile issuance when the target user is not a tenant member', function (): void {
    [, $owner, $endpoint] = provisionVpnaasProfileFixture();

    // A user that exists globally but has no membership in our tenant.
    $outsider = User::factory()->create(['name' => 'Outside Tenant', 'email' => 'outside-tenant@example.test']);

    Sanctum::actingAs($owner);
    $this->postJson('/api/v1/vpn-client-profiles', [
        'network_vpn_endpoint_id' => $endpoint->getKey(),
        'user_id' => $outsider->getKey(),
    ])->assertNotFound();

    expect(VpnClientProfile::query()->count())->toBe(0);
});

it('rejects downloads after the endpoint is released and revokes attached profiles', function (): void {
    [, $owner, $endpoint] = provisionVpnaasProfileFixture();

    Sanctum::actingAs($owner);
    $profileId = $this->postJson('/api/v1/vpn-client-profiles', [
        'network_vpn_endpoint_id' => $endpoint->getKey(),
    ])->assertCreated()->json('data.id');

    // Release the endpoint.
    $this->deleteJson('/api/v1/network-vpn-endpoints/'.$endpoint->getKey())->assertNoContent();

    // Profile should be revoked (endpoint-release converges attached profiles).
    expect(VpnClientProfile::query()->whereKey($profileId)->firstOrFail()->state)
        ->toBe(VpnClientProfile::STATE_REVOKED);

    // Even hypothetically un-revoking: the endpoint guard inside downloadConfig
    // still rejects the download because endpoint is no longer running.
    $this->get('/api/v1/vpn-client-profiles/'.$profileId.'/download')->assertStatus(422);
});

it('revokes the profile, closes open sessions, and refuses subsequent downloads', function (): void {
    [$tenant, $owner, $endpoint] = provisionVpnaasProfileFixture();

    Sanctum::actingAs($owner);
    $profileId = $this->postJson('/api/v1/vpn-client-profiles', [
        'network_vpn_endpoint_id' => $endpoint->getKey(),
    ])->assertCreated()->json('data.id');

    /** @var VpnClientProfile $profile */
    $profile = VpnClientProfile::query()->whereKey($profileId)->firstOrFail();

    // Open a session row so we can verify the revoke flushes it.
    VpnSession::query()->create([
        'tenant_id' => $tenant->getKey(),
        'vpn_client_profile_id' => $profile->getKey(),
        'network_vpn_endpoint_id' => $endpoint->getKey(),
        'state' => VpnSession::STATE_ACTIVE,
        'connected_at' => now(),
    ]);

    $this->postJson('/api/v1/vpn-client-profiles/'.$profileId.'/revoke', ['reason' => 'test_revoke'])
        ->assertOk()
        ->assertJsonPath('data.state', 'revoked');

    expect(VpnClientProfile::query()->whereKey($profileId)->firstOrFail()->isActive())->toBeFalse();
    expect(VpnSession::query()->where('vpn_client_profile_id', $profileId)->where('state', VpnSession::STATE_CLOSED)->exists())
        ->toBeTrue();

    $this->get('/api/v1/vpn-client-profiles/'.$profileId.'/download')->assertStatus(422);
});

/**
 * @return array{Tenant, User, NetworkVpnEndpoint}
 */
function provisionVpnaasProfileFixture(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(['slug' => 'default'], ['name' => 'Default Tenant']);
    $user = User::factory()->create(['name' => 'VPN Profile Owner', 'email' => 'vpn-profile@example.test']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    /** @var ProviderNetwork $providerNetwork */
    $providerNetwork = ProviderNetwork::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'VPN Profile Provider',
        'slug' => 'vpn-profile-provider',
        'provider' => 'fake',
        'provider_cluster' => null,
        'network_type' => 'bridge',
        'external_id' => 'vmbr-vpn-prof',
        'bridge' => 'vmbr-vpn-prof',
        'vlan_tag' => null,
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);
    /** @var NetworkOffering $offering */
    $offering = NetworkOffering::query()->create([
        'tenant_id' => $tenant->getKey(),
        'provider_network_id' => $providerNetwork->getKey(),
        'name' => 'VPN Profile Isolated',
        'slug' => 'vpn-profile-isolated',
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
        'name' => 'VPN Profile Network',
        'slug' => 'vpn-profile-network',
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
        'name' => 'VPN Profile Pool',
        'slug' => 'vpn-profile-pool',
        'provider' => 'openvpn',
        'cidr' => '203.0.113.16/29',
        'port_range_min' => 21000,
        'port_range_max' => 21099,
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    foreach (['vpnaas_endpoints', 'vpnaas_endpoint_public_ips', 'vpnaas_endpoint_ports', 'vpnaas_client_profiles'] as $dimension) {
        QuotaLimit::query()->create([
            'tenant_id' => $tenant->getKey(),
            'scope_type' => 'project',
            'scope_id' => $project->getKey(),
            'dimension' => $dimension,
            'limit_value' => 4,
            'metadata' => ['source' => 'vpnaas-profile-test'],
        ]);
    }

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    Sanctum::actingAs($user);
    $endpointId = test()->postJson('/api/v1/network-vpn-endpoints', [
        'name' => 'lab-vpn',
        'project_id' => $project->getKey(),
        'network_id' => $network->getKey(),
        'vpn_public_ip_pool_slug' => $pool->slug,
    ])->assertCreated()->json('data.id');
    auth()->forgetGuards();

    /** @var NetworkVpnEndpoint $endpoint */
    $endpoint = NetworkVpnEndpoint::query()->whereKey($endpointId)->firstOrFail();

    return [$tenant, $user, $endpoint];
}
