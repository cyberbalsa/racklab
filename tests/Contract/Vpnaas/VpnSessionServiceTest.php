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
use App\Models\Tenant;
use App\Models\User;
use App\Models\VpnClientProfile;
use App\Models\VpnPublicIpPool;
use App\Models\VpnSession;
use App\Networking\VpnSessionService;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('records a connect event with peer_ip and audits session_connect', function (): void {
    [$tenant, $owner, $profile] = provisionVpnSessionFixture();

    $session = app(VpnSessionService::class)->recordConnect(
        actor: $owner,
        context: new TenantContext(activeTenantId: $tenant->getKey()),
        profile: $profile,
        peerIp: '198.51.100.5',
    );

    expect($session->state)->toBe(VpnSession::STATE_ACTIVE)
        ->and($session->peer_ip)->toBe('198.51.100.5')
        ->and($session->connected_at)->not->toBeNull();

    expect(AuditEvent::query()
        ->where('event_type', 'network.vpnaas.profile')
        ->where('action', 'session_connect')
        ->where('result', 'allowed')
        ->exists()
    )->toBeTrue();
});

it('refuses connect when the profile is revoked', function (): void {
    [$tenant, $owner, $profile] = provisionVpnSessionFixture();
    $profile->forceFill(['state' => VpnClientProfile::STATE_REVOKED, 'revoked_at' => now()])->save();

    expect(fn () => app(VpnSessionService::class)->recordConnect(
        actor: $owner,
        context: new TenantContext(activeTenantId: $tenant->getKey()),
        profile: $profile->fresh(),
        peerIp: '198.51.100.5',
    ))->toThrow(ValidationException::class);
});

it('refuses connect when the endpoint is not running', function (): void {
    [$tenant, $owner, $profile, $endpoint] = provisionVpnSessionFixture();
    $endpoint->forceFill(['state' => NetworkVpnEndpoint::STATE_STOPPED])->save();

    expect(fn () => app(VpnSessionService::class)->recordConnect(
        actor: $owner,
        context: new TenantContext(activeTenantId: $tenant->getKey()),
        profile: $profile,
        peerIp: '198.51.100.5',
    ))->toThrow(ValidationException::class);
});

it('records a disconnect event with byte counts and audits session_disconnect', function (): void {
    [$tenant, $owner, $profile] = provisionVpnSessionFixture();
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    $session = app(VpnSessionService::class)->recordConnect($owner, $context, $profile, '198.51.100.5');
    $closed = app(VpnSessionService::class)->recordDisconnect($owner, $context, $session, 'client_quit', bytesIn: 1024, bytesOut: 2048);

    expect($closed->state)->toBe(VpnSession::STATE_CLOSED)
        ->and($closed->disconnect_reason)->toBe('client_quit')
        ->and($closed->bytes_in)->toBe(1024)
        ->and($closed->bytes_out)->toBe(2048)
        ->and($closed->disconnected_at)->not->toBeNull();

    expect(AuditEvent::query()
        ->where('event_type', 'network.vpnaas.profile')
        ->where('action', 'session_disconnect')
        ->where('result', 'allowed')
        ->exists()
    )->toBeTrue();
});

it('lists session history for the owner through GET /sessions', function (): void {
    [$tenant, $owner, $profile] = provisionVpnSessionFixture();
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    $session = app(VpnSessionService::class)->recordConnect($owner, $context, $profile, '198.51.100.5');
    app(VpnSessionService::class)->recordDisconnect($owner, $context, $session, 'client_quit', bytesIn: 100, bytesOut: 200);

    Sanctum::actingAs($owner);
    $this->getJson('/api/v1/vpn-client-profiles/'.$profile->getKey().'/sessions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.state', VpnSession::STATE_CLOSED)
        ->assertJsonPath('data.0.bytes_in', 100)
        ->assertJsonPath('data.0.bytes_out', 200);
});

it('rejects session listing when the actor is not the owner and lacks session.read', function (): void {
    [$tenant, $owner, $profile] = provisionVpnSessionFixture();
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(VpnSessionService::class)->recordConnect($owner, $context, $profile, '198.51.100.5');

    $outsider = User::factory()->create(['name' => 'Outsider', 'email' => 'session-outsider@example.test']);
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    app(PersonalProjectProvisioner::class)->ensureFor($outsider, $context);
    // Strip the outsider's default project role bindings so they have no session.read.
    App\Models\RoleBinding::query()
        ->where('principal_type', 'user')
        ->where('principal_id', (string) $outsider->getKey())
        ->delete();
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    Sanctum::actingAs($outsider);
    $this->getJson('/api/v1/vpn-client-profiles/'.$profile->getKey().'/sessions')
        ->assertForbidden();
});

/**
 * @return array{Tenant, User, VpnClientProfile, NetworkVpnEndpoint}
 */
function provisionVpnSessionFixture(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(['slug' => 'default'], ['name' => 'Default Tenant']);
    $user = User::factory()->create(['name' => 'Session Owner', 'email' => 'session-owner@example.test']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    /** @var ProviderNetwork $providerNetwork */
    $providerNetwork = ProviderNetwork::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Session Provider',
        'slug' => 'session-provider',
        'provider' => 'fake',
        'provider_cluster' => null,
        'network_type' => 'bridge',
        'external_id' => 'vmbr-session',
        'bridge' => 'vmbr-session',
        'vlan_tag' => null,
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);
    /** @var NetworkOffering $offering */
    $offering = NetworkOffering::query()->create([
        'tenant_id' => $tenant->getKey(),
        'provider_network_id' => $providerNetwork->getKey(),
        'name' => 'Session Isolated',
        'slug' => 'session-isolated',
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
        'name' => 'Session Network',
        'slug' => 'session-network',
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
        'name' => 'Session Pool',
        'slug' => 'session-pool',
        'provider' => 'openvpn',
        'cidr' => '203.0.113.40/29',
        'port_range_min' => 22000,
        'port_range_max' => 22099,
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
            'metadata' => ['source' => 'session-test'],
        ]);
    }

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    Sanctum::actingAs($user);
    $endpointId = test()->postJson('/api/v1/network-vpn-endpoints', [
        'name' => 'session-vpn',
        'project_id' => $project->getKey(),
        'network_id' => $network->getKey(),
        'vpn_public_ip_pool_slug' => $pool->slug,
    ])->assertCreated()->json('data.id');

    $profileId = test()->postJson('/api/v1/vpn-client-profiles', [
        'network_vpn_endpoint_id' => $endpointId,
    ])->assertCreated()->json('data.id');
    auth()->forgetGuards();

    /** @var NetworkVpnEndpoint $endpoint */
    $endpoint = NetworkVpnEndpoint::query()->whereKey($endpointId)->firstOrFail();
    /** @var VpnClientProfile $profile */
    $profile = VpnClientProfile::query()->whereKey($profileId)->firstOrFail();

    return [$tenant, $user, $profile, $endpoint];
}
