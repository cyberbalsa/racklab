<?php

declare(strict_types=1);

use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\DeploymentHostKey;
use App\Models\HostKeyPhoneHomeToken;
use App\Models\Project;
use App\Models\ProjectDefaultStack;
use App\Models\ProjectSshKey;
use App\Models\RoleBinding;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('creates and lists project SSH keys with server-computed fingerprints', function (): void {
    [, $admin, $project] = provisionCloudInitProject(role: 'admin');

    Sanctum::actingAs($admin);

    $key = $this->postJson('/api/v1/projects/'.$project->getKey().'/ssh-keys', [
        'name' => 'Laptop',
        'public_key' => cloudInitPublicKey('project-key'),
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Laptop')
        ->json('data');

    expect($key['fingerprint'])->toStartWith('SHA256:')
        ->and(ProjectSshKey::query()->whereKey($key['id'])->where('project_id', $project->getKey())->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', 'project.ssh_key')->where('result', 'allowed')->exists())->toBeTrue();

    $this->getJson('/api/v1/projects/'.$project->getKey().'/ssh-keys')
        ->assertOk()
        ->assertJsonPath('data.0.id', $key['id'])
        ->assertJsonPath('data.0.fingerprint', $key['fingerprint']);
});

it('attaches cloud-init provisioning metadata to a deployment without storing the phone-home token in deployment metadata', function (): void {
    Queue::fake();

    [, $admin, $project] = provisionCloudInitProject(role: 'admin', email: 'cloud-init-admin@example.test');

    Sanctum::actingAs($admin);

    $deployment = $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'cloud-init-deploy',
    ])->assertCreated()->json('data');
    $script = $this->postJson('/api/v1/scripts', [
        'project_id' => $project->getKey(),
        'name' => 'Linux bootstrap',
        'runner_kind' => 'cloudinit',
        'command' => ['cloud-init'],
        'source' => "#cloud-config\npackage_update: true\n",
    ])->assertCreated()->json('data');
    $sshKey = $this->postJson('/api/v1/projects/'.$project->getKey().'/ssh-keys', [
        'name' => 'Lab key',
        'public_key' => cloudInitPublicKey('lab-key'),
    ])->assertCreated()->json('data');

    $response = $this->postJson('/api/v1/deployments/'.$deployment['id'].'/cloud-init', [
        'script_version_id' => $script['current_version']['id'],
        'project_ssh_key_ids' => [$sshKey['id']],
    ])
        ->assertOk()
        ->assertJsonPath('data.deployment_id', $deployment['id']);

    $rendered = (string) $response->json('data.rendered_cloud_init');
    $phoneHomeUrl = (string) $response->json('data.phone_home_url');

    expect($rendered)->toContain((string) $sshKey['public_key'])
        ->and($rendered)->toContain($phoneHomeUrl)
        ->and($phoneHomeUrl)->toContain('/api/v1/provisioning/host-keys/')
        ->and(HostKeyPhoneHomeToken::query()->where('deployment_id', $deployment['id'])->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', 'cloud_init.render')->where('result', 'allowed')->exists())->toBeTrue();

    $metadata = Deployment::query()->whereKey($deployment['id'])->firstOrFail()->metadata;
    $token = basename($phoneHomeUrl);

    expect($metadata['cloud_init']['script_version_id'])->toBe($script['current_version']['id'])
        ->and($metadata['cloud_init']['rendered_redacted'])->not->toContain($token);
});

it('accepts host-key phone-home once for a deployment-scoped token and audits reuse denial', function (): void {
    Queue::fake();

    [, $admin, $project] = provisionCloudInitProject(role: 'admin', email: 'phone-home-admin@example.test');

    Sanctum::actingAs($admin);

    $deployment = $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'phone-home-deploy',
    ])->assertCreated()->json('data');
    $script = $this->postJson('/api/v1/scripts', [
        'project_id' => $project->getKey(),
        'name' => 'Host key capture',
        'runner_kind' => 'cloudinit',
        'command' => ['cloud-init'],
        'source' => "#cloud-config\n",
    ])->assertCreated()->json('data');

    $phoneHomeUrl = (string) $this->postJson('/api/v1/deployments/'.$deployment['id'].'/cloud-init', [
        'script_version_id' => $script['current_version']['id'],
        'project_ssh_key_ids' => [],
    ])->assertOk()->json('data.phone_home_url');
    $token = basename($phoneHomeUrl);
    $hostPublicKey = cloudInitPublicKey('guest-host-key');

    $this->postJson('/api/v1/provisioning/host-keys/'.$token, [
        'keys' => [
            ['public_key' => $hostPublicKey],
        ],
    ])->assertOk()->assertJsonPath('data.keys_recorded', 1);

    expect(DeploymentHostKey::query()->where('deployment_id', $deployment['id'])->where('public_key', $hostPublicKey)->exists())->toBeTrue()
        ->and(HostKeyPhoneHomeToken::query()->where('deployment_id', $deployment['id'])->firstOrFail()->used_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('event_type', 'host_key.phone_home')->where('result', 'allowed')->exists())->toBeTrue();

    $this->postJson('/api/v1/provisioning/host-keys/'.$token, [
        'keys' => [
            ['public_key' => $hostPublicKey],
        ],
    ])->assertNotFound();

    expect(AuditEvent::query()->where('event_type', 'host_key.phone_home')->where('result', 'denied')->exists())->toBeTrue();
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionCloudInitProject(string $role, ?string $email = null): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(
        ['slug' => 'default'],
        ['name' => 'Default Tenant'],
    );
    $user = User::factory()->create([
        'name' => 'Cloud Init '.$role,
        'email' => $email ?? fake()->unique()->safeEmail(),
    ]);
    TenantMembership::query()->create([
        'tenant_id' => $tenant->getKey(),
        'user_id' => $user->getKey(),
        'is_primary' => true,
    ]);

    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    /** @var Project $project */
    $project = Project::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Cloud Init Project',
        'slug' => 'cloud-init-project-'.strtolower($role).'-'.substr((string) $user->getKey(), -6),
        'created_for_user_id' => $user->getKey(),
        'is_personal_default' => false,
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);
    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $user->id,
        'role' => $role,
        'resource_type' => $project->resourceType(),
        'resource_id' => $project->resourceId(),
        'scope_type' => RoleBindingScopeType::TenantLocal,
        'tenant_id' => $tenant->getKey(),
        'tenant_set' => [$tenant->getKey()],
        'granted_by_id' => $user->getKey(),
        'granted_reason' => 'cloud-init test fixture',
    ]);
    $stack = StackDefinition::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'name' => 'Default',
        'slug' => 'default',
        'scope' => 'project_local',
        'is_reserved_default' => true,
        'definition' => [
            'version' => 1,
            'components' => [],
        ],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);
    ProjectDefaultStack::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'stack_definition_id' => $stack->getKey(),
        'active_deployment_id' => null,
    ]);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}

function cloudInitPublicKey(string $seed): string
{
    return 'ssh-ed25519 '.base64_encode('racklab-'.$seed).' '.$seed;
}
