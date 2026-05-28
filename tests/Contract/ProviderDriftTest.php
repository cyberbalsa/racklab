<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\AuditEvent;
use App\Models\Network;
use App\Models\NetworkOffering;
use App\Models\Project;
use App\Models\ProviderDrift;
use App\Models\ProviderNetwork;
use App\Models\RoleBinding;
use App\Models\SecurityGroup;
use App\Models\SecurityGroupRule;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('detects network provider drift and repairs it by reasserting RackLab intent', function (): void {
    [$tenant, $user, $project] = provisionProviderDriftProject();
    $network = createProviderDriftNetwork($tenant, $project);
    $observed = providerDriftObservedNetworkState($network, state: 'externally_disabled');

    $network->forceFill(['metadata' => ['provider_observed_state' => $observed]])->save();

    $this->artisan('racklab:detect-provider-drift', ['--tenant' => 'default', '--provider' => 'fake'])
        ->expectsOutput('Detected 1 provider drift(s).')
        ->assertExitCode(0);

    /** @var ProviderDrift $drift */
    $drift = ProviderDrift::query()->firstOrFail();

    expect($drift->resource_type)->toBe('network')
        ->and($drift->resource_id)->toBe($network->getKey())
        ->and($drift->state)->toBe('detected')
        ->and($drift->drift[0]['path'])->toBe('state')
        ->and(AuditEvent::query()->where('event_type', 'provider.drift')->where('action', 'detected')->exists())->toBeTrue();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/provider-drifts/'.$drift->getKey().'/repair')
        ->assertOk()
        ->assertJsonPath('data.state', 'repaired')
        ->assertJsonPath('data.resolution', 'repair');

    expect($network->refresh()->metadata['provider_observed_state']['state'])->toBe('active')
        ->and($drift->refresh()->state)->toBe('repaired')
        ->and(AuditEvent::query()->where('event_type', 'provider.drift')->where('action', 'repaired')->exists())->toBeTrue();
});

it('adopts externally modified security group rules into RackLab state', function (): void {
    [$tenant, $user, $project] = provisionProviderDriftProject();
    $securityGroup = createProviderDriftSecurityGroup($tenant, $project);
    $observed = providerDriftObservedSecurityGroupState($securityGroup, [
        [
            'position' => 1,
            'direction' => 'ingress',
            'protocol' => 'tcp',
            'ethertype' => 'IPv4',
            'port_min' => 2222,
            'port_max' => 2222,
            'remote_cidr' => '203.0.113.0/24',
            'state' => 'active',
            'provider_rule_id' => 'fake-provider-rule-2222',
            'provider_binding' => ['provider' => 'fake', 'mode' => 'externally-modified'],
        ],
    ]);

    $securityGroup->forceFill(['metadata' => ['provider_observed_state' => $observed]])->save();

    $this->artisan('racklab:detect-provider-drift')
        ->expectsOutput('Detected 1 provider drift(s).')
        ->assertExitCode(0);

    /** @var ProviderDrift $drift */
    $drift = ProviderDrift::query()->firstOrFail();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/provider-drifts/'.$drift->getKey().'/adopt')
        ->assertOk()
        ->assertJsonPath('data.state', 'adopted')
        ->assertJsonPath('data.resolution', 'adopt');

    /** @var SecurityGroupRule $rule */
    $rule = SecurityGroupRule::query()->where('security_group_id', $securityGroup->getKey())->firstOrFail();

    expect($rule->port_min)->toBe(2222)
        ->and($rule->remote_cidr)->toBe('203.0.113.0/24')
        ->and($rule->provider_rule_id)->toBe('fake-provider-rule-2222')
        ->and($securityGroup->refresh()->metadata)->not->toHaveKey('provider_observed_state')
        ->and(AuditEvent::query()->where('event_type', 'provider.drift')->where('action', 'adopted')->exists())->toBeTrue();
});

it('denies provider drift repair without provider-management permission and audits the denial', function (): void {
    [$tenant, $user, $project] = provisionProviderDriftProject();
    $network = createProviderDriftNetwork($tenant, $project);

    $network->forceFill([
        'metadata' => [
            'provider_observed_state' => providerDriftObservedNetworkState($network, state: 'externally_disabled'),
        ],
    ])->save();

    $this->artisan('racklab:detect-provider-drift')
        ->expectsOutput('Detected 1 provider drift(s).')
        ->assertExitCode(0);

    /** @var ProviderDrift $drift */
    $drift = ProviderDrift::query()->firstOrFail();

    RoleBinding::query()
        ->where('principal_type', 'user')
        ->where('principal_id', (string) $user->id)
        ->delete();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/provider-drifts/'.$drift->getKey().'/repair')
        ->assertForbidden();

    expect($drift->refresh()->state)->toBe('detected')
        ->and(AuditEvent::query()->where('event_type', 'provider.drift')->where('action', 'repair')->where('result', 'denied')->exists())->toBeTrue();
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionProviderDriftProject(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create([
        'name' => 'Provider Drift Admin',
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

function createProviderDriftNetwork(Tenant $tenant, Project $project): Network
{
    /** @var ProviderNetwork $providerNetwork */
    $providerNetwork = ProviderNetwork::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Provider Drift Bridge',
        'slug' => 'provider-drift-bridge',
        'provider' => 'fake',
        'provider_cluster' => null,
        'network_type' => 'bridge',
        'external_id' => 'vmbr-drift',
        'bridge' => 'vmbr-drift',
        'vlan_tag' => null,
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    /** @var NetworkOffering $offering */
    $offering = NetworkOffering::query()->create([
        'tenant_id' => $tenant->getKey(),
        'provider_network_id' => $providerNetwork->getKey(),
        'name' => 'Provider Drift NAT',
        'slug' => 'provider-drift-nat',
        'offering_type' => 'private-nat',
        'reachability' => 'nat_from_management',
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    /** @var Network $network */
    $network = Network::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'network_offering_id' => $offering->getKey(),
        'name' => 'Drifted Network',
        'slug' => 'drifted-network',
        'state' => 'active',
        'provider' => 'fake',
        'reachability' => 'nat_from_management',
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    return $network;
}

/**
 * @return array<string, mixed>
 */
function providerDriftObservedNetworkState(Network $network, string $state): array
{
    return [
        'resource_type' => 'network',
        'id' => $network->getKey(),
        'name' => $network->name,
        'slug' => $network->slug,
        'state' => $state,
        'provider' => $network->provider,
        'reachability' => $network->reachability,
        'subnets' => [],
    ];
}

function createProviderDriftSecurityGroup(Tenant $tenant, Project $project): SecurityGroup
{
    /** @var SecurityGroup $securityGroup */
    $securityGroup = SecurityGroup::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'name' => 'SSH Security Group',
        'slug' => 'ssh-security-group',
        'state' => 'active',
        'provider' => 'fake',
        'provider_security_group_id' => 'fake-sg-drift',
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    SecurityGroupRule::query()->create([
        'tenant_id' => $tenant->getKey(),
        'security_group_id' => $securityGroup->getKey(),
        'position' => 1,
        'direction' => 'ingress',
        'protocol' => 'tcp',
        'ethertype' => 'IPv4',
        'port_min' => 22,
        'port_max' => 22,
        'remote_cidr' => '0.0.0.0/0',
        'state' => 'active',
        'provider_rule_id' => 'fake-provider-rule-22',
        'provider_binding' => ['provider' => 'fake', 'mode' => 'fake-firewall-rule'],
        'metadata' => [],
    ]);

    return $securityGroup;
}

/**
 * @param  list<array<string, mixed>>  $rules
 * @return array<string, mixed>
 */
function providerDriftObservedSecurityGroupState(SecurityGroup $securityGroup, array $rules): array
{
    return [
        'resource_type' => 'security_group',
        'id' => $securityGroup->getKey(),
        'name' => $securityGroup->name,
        'slug' => $securityGroup->slug,
        'state' => $securityGroup->state,
        'provider' => $securityGroup->provider,
        'provider_security_group_id' => $securityGroup->provider_security_group_id,
        'rules' => $rules,
    ];
}
