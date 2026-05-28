<?php

declare(strict_types=1);

use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\DeploymentNetworkBinding;
use App\Models\DeploymentOperation;
use App\Models\NetworkOffering;
use App\Models\Project;
use App\Models\ProviderNetwork;
use App\Models\ProviderTask;
use App\Models\RoleBinding;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('allows tenant admins to publish provider-backed network offerings and audits denials', function (): void {
    [$tenant, $admin] = provisionNetworkingUser(admin: true);
    [, $student] = provisionNetworkingUser(email: 'network-student@example.test');

    Sanctum::actingAs($student);

    $this->postJson('/api/v1/network-offerings', networkOfferingPayload('private-isolated'))
        ->assertForbidden();

    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/network-offerings', networkOfferingPayload('private-isolated'))
        ->assertCreated()
        ->assertJsonPath('data.slug', 'private-isolated')
        ->assertJsonPath('data.reachability', 'isolated_no_ingress')
        ->assertJsonPath('data.provider_network.external_id', 'vmbr100');

    expect($response->json('data.id'))->toBeString()
        ->and(ProviderNetwork::query()->where('external_id', 'vmbr100')->exists())->toBeTrue()
        ->and(NetworkOffering::query()->where('slug', 'private-isolated')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', 'network.offering')->where('result', 'denied')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', 'network.offering')->where('result', 'allowed')->exists())->toBeTrue();
});

it('resolves stack network offerings into deployment network bindings and dashboard reachability', function (): void {
    [$tenant, $admin] = provisionNetworkingUser(admin: true);
    [, $student, $project] = provisionNetworkingUser(email: 'network-deployer@example.test');
    publishNetworkOffering($tenant, $admin, networkOfferingPayload('private-isolated'));
    $stack = createNetworkedStack($tenant, $project, 'isolated-stack', 'private-isolated');

    Sanctum::actingAs($student);

    $deployment = $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'stack_definition_id' => $stack->getKey(),
        'operation' => 'deploy',
        'idempotency_key' => 'network-isolated-deploy',
    ])
        ->assertCreated()
        ->assertJsonPath('data.resources.0.networks.0.offering_slug', 'private-isolated')
        ->assertJsonPath('data.resources.0.networks.0.reachability', 'isolated_no_ingress')
        ->json('data');

    expect(DeploymentNetworkBinding::query()->where('deployment_id', $deployment['id'])->count())->toBe(1)
        ->and(DeploymentNetworkBinding::query()->firstOrFail()->provider_binding['bridge'] ?? null)->toBe('vmbr100');

    $this->actingAs($student)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('SSH not available');
});

it('records NAT reachability metadata for deployment network bindings', function (): void {
    [$tenant, $admin] = provisionNetworkingUser(admin: true);
    [, $student, $project] = provisionNetworkingUser(email: 'network-nat@example.test');
    publishNetworkOffering($tenant, $admin, networkOfferingPayload('private-nat'));
    $stack = createNetworkedStack($tenant, $project, 'nat-stack', 'private-nat');

    Sanctum::actingAs($student);

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'stack_definition_id' => $stack->getKey(),
        'operation' => 'deploy',
        'idempotency_key' => 'network-nat-deploy',
    ])
        ->assertCreated()
        ->assertJsonPath('data.resources.0.networks.0.reachability', 'nat_from_management')
        ->assertJsonPath('data.resources.0.networks.0.management_host', '198.51.100.10')
        ->assertJsonPath('data.resources.0.networks.0.management_port', 2201);

    $binding = DeploymentNetworkBinding::query()->firstOrFail();

    expect($binding->management_host)->toBe('198.51.100.10')
        ->and($binding->management_port)->toBe(2201)
        ->and($binding->metadata['nat']['mode'] ?? null)->toBe('static_port_forward');
});

it('rejects stack network specs without an offering before provider work starts', function (): void {
    [$tenant, $student, $project] = provisionNetworkingUser(email: 'network-missing-offering@example.test');
    $stack = createStackWithNetworks($tenant, $project, 'missing-offering-stack', [[
        'key' => 'eth0',
    ]]);

    Sanctum::actingAs($student);

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'stack_definition_id' => $stack->getKey(),
        'operation' => 'deploy',
        'idempotency_key' => 'network-missing-offering',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('network_offering');

    expect(Deployment::query()->count())->toBe(0)
        ->and(DeploymentOperation::query()->count())->toBe(0)
        ->and(ProviderTask::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', 'network.attach')->where('result', 'denied')->exists())->toBeTrue();
});

it('rejects fake deployments that reference non-fake network offerings before provider work starts', function (): void {
    [$tenant, $admin] = provisionNetworkingUser(admin: true);
    [, $student, $project] = provisionNetworkingUser(email: 'network-provider-mismatch@example.test');
    publishNetworkOffering($tenant, $admin, networkOfferingPayload('proxmox-isolated'));
    $stack = createNetworkedStack($tenant, $project, 'provider-mismatch-stack', 'proxmox-isolated');

    Sanctum::actingAs($student);

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'stack_definition_id' => $stack->getKey(),
        'operation' => 'deploy',
        'idempotency_key' => 'network-provider-mismatch',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('network_offering');

    expect(Deployment::query()->count())->toBe(0)
        ->and(DeploymentOperation::query()->count())->toBe(0)
        ->and(ProviderTask::query()->count())->toBe(0);
});

it('rejects provider network types that the selected provider does not support', function (): void {
    [$tenant, $admin] = provisionNetworkingUser(admin: true);
    [, $student, $project] = provisionNetworkingUser(email: 'network-unsupported-type@example.test');
    publishNetworkOffering($tenant, $admin, networkOfferingPayload('fake-vlan'));
    $stack = createNetworkedStack($tenant, $project, 'unsupported-network-type-stack', 'fake-vlan');

    Sanctum::actingAs($student);

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'stack_definition_id' => $stack->getKey(),
        'operation' => 'deploy',
        'idempotency_key' => 'network-unsupported-type',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('network_offering');

    expect(Deployment::query()->count())->toBe(0)
        ->and(DeploymentOperation::query()->count())->toBe(0)
        ->and(ProviderTask::query()->count())->toBe(0);
});

it('rejects Proxmox deployments that reference non-Proxmox network offerings before clone work starts', function (): void {
    [$tenant, $admin] = provisionNetworkingUser(admin: true);
    [, $student, $project] = provisionNetworkingUser(email: 'network-proxmox-mismatch@example.test');
    publishNetworkOffering($tenant, $admin, networkOfferingPayload('private-isolated'));
    $stack = createProxmoxNetworkedStack($tenant, $project, 'proxmox-network-mismatch-stack', 'private-isolated');

    Sanctum::actingAs($student);

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'stack_definition_id' => $stack->getKey(),
        'operation' => 'deploy',
        'idempotency_key' => 'network-proxmox-mismatch',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('network_offering');

    expect(Deployment::query()->count())->toBe(0)
        ->and(DeploymentOperation::query()->count())->toBe(0)
        ->and(ProviderTask::query()->count())->toBe(0);
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionNetworkingUser(bool $admin = false, ?string $email = null): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(
        ['slug' => 'default'],
        ['name' => 'Default Tenant'],
    );
    $user = User::factory()->create([
        'name' => $admin ? 'Network Admin' : 'Network Student',
        'email' => $email ?? fake()->unique()->safeEmail(),
    ]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    if ($admin) {
        RoleBinding::query()->create([
            'principal_type' => 'user',
            'principal_id' => (string) $user->id,
            'role' => 'admin',
            'resource_type' => 'tenant',
            'resource_id' => $tenant->getKey(),
            'scope_type' => RoleBindingScopeType::TenantLocal,
            'tenant_id' => $tenant->getKey(),
            'tenant_set' => [$tenant->getKey()],
            'granted_by_id' => $user->getKey(),
            'granted_reason' => 'networking fixture',
        ]);
    }

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}

/**
 * @return array<string, mixed>
 */
function networkOfferingPayload(string $slug): array
{
    if ($slug === 'proxmox-isolated') {
        return [
            'name' => 'Proxmox Isolated',
            'slug' => 'proxmox-isolated',
            'offering_type' => 'private-isolated',
            'reachability' => 'isolated_no_ingress',
            'provider_network' => [
                'name' => 'Proxmox Bridge',
                'provider' => 'proxmox',
                'external_id' => 'vmbr300',
                'network_type' => 'bridge',
                'bridge' => 'vmbr300',
            ],
        ];
    }

    if ($slug === 'fake-vlan') {
        return [
            'name' => 'Fake VLAN',
            'slug' => 'fake-vlan',
            'offering_type' => 'private-isolated',
            'reachability' => 'isolated_no_ingress',
            'provider_network' => [
                'name' => 'Fake VLAN',
                'provider' => 'fake',
                'external_id' => 'vlan-400',
                'network_type' => 'vlan',
                'vlan_tag' => 400,
            ],
        ];
    }

    if ($slug === 'private-nat') {
        return [
            'name' => 'Private NAT',
            'slug' => 'private-nat',
            'offering_type' => 'private-nat',
            'reachability' => 'nat_from_management',
            'provider_network' => [
                'name' => 'NAT Bridge',
                'provider' => 'fake',
                'external_id' => 'vmbr200',
                'network_type' => 'bridge',
                'bridge' => 'vmbr200',
            ],
            'metadata' => [
                'nat' => [
                    'mode' => 'static_port_forward',
                    'host' => '198.51.100.10',
                    'port' => 2201,
                ],
            ],
        ];
    }

    return [
        'name' => 'Private Isolated',
        'slug' => 'private-isolated',
        'offering_type' => 'private-isolated',
        'reachability' => 'isolated_no_ingress',
        'provider_network' => [
            'name' => 'Isolated Bridge',
            'provider' => 'fake',
            'external_id' => 'vmbr100',
            'network_type' => 'bridge',
            'bridge' => 'vmbr100',
        ],
    ];
}

function publishNetworkOffering(Tenant $tenant, User $admin, array $payload): void
{
    Sanctum::actingAs($admin);
    test()->postJson('/api/v1/network-offerings', $payload)->assertCreated();
    Tenant::forgetCurrent();
    app(TenantContextStore::class)->forget();
}

function createNetworkedStack(Tenant $tenant, Project $project, string $slug, string $offeringSlug): StackDefinition
{
    return createStackWithNetworks($tenant, $project, $slug, [[
        'key' => 'eth0',
        'offering_slug' => $offeringSlug,
    ]]);
}

/**
 * @param  list<array<string, mixed>>  $networks
 */
function createStackWithNetworks(Tenant $tenant, Project $project, string $slug, array $networks): StackDefinition
{
    /** @var StackDefinition $stack */
    $stack = StackDefinition::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'name' => str_replace('-', ' ', ucfirst($slug)),
        'slug' => $slug,
        'scope' => 'project_local',
        'is_reserved_default' => false,
        'definition' => [
            'provider' => 'fake',
            'components' => [[
                'key' => 'vm',
                'kind' => 'vm',
                'networks' => $networks,
            ]],
        ],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    return $stack;
}

function createProxmoxNetworkedStack(Tenant $tenant, Project $project, string $slug, string $offeringSlug): StackDefinition
{
    /** @var StackDefinition $stack */
    $stack = StackDefinition::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'name' => str_replace('-', ' ', ucfirst($slug)),
        'slug' => $slug,
        'scope' => 'project_local',
        'is_reserved_default' => false,
        'definition' => [
            'provider' => 'proxmox',
            'components' => [[
                'key' => 'vm',
                'kind' => 'vm',
                'provider' => 'proxmox',
                'proxmox' => [
                    'node' => 'pve01',
                    'template_vmid' => 9000,
                    'target_vmid' => 101,
                    'name' => 'racklab-test-vm',
                    'full_clone' => true,
                    'storage' => 'local-lvm',
                ],
                'networks' => [[
                    'key' => 'eth0',
                    'offering_slug' => $offeringSlug,
                ]],
            ]],
        ],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    return $stack;
}
