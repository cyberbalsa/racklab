<?php

declare(strict_types=1);

use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectSshKey;
use App\Models\RoleBinding;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{Tenant, User, Project}
 */
function provisionProjectDetailActor(string $name = 'Owner'): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->firstOrCreate(['slug' => 'default'], ['name' => 'Default Tenant']);
    $user = User::factory()->create(['name' => $name]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}

function seedProjectDetailContents(Tenant $tenant, User $owner, Project $project): void
{
    app(TenantContextStore::class)->set(new TenantContext(activeTenantId: $tenant->getKey()));
    $tenant->makeCurrent();

    $stack = StackDefinition::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'name' => 'Two-tier Lab',
        'slug' => 'two-tier-lab',
        'scope' => 'project_local',
        'is_reserved_default' => false,
        'definition' => ['provider' => 'fake', 'components' => []],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    /** @var Deployment $deployment */
    $deployment = Deployment::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'stack_definition_id' => $stack->getKey(),
        'requested_by_id' => $owner->id,
        'name' => 'lab-web-01',
        'state' => 'running',
        'provider' => 'fake',
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $owner->id,
        'role' => 'student',
        'resource_type' => $deployment->resourceType(),
        'resource_id' => $deployment->resourceId(),
        'scope_type' => RoleBindingScopeType::TenantLocal,
        'tenant_id' => $tenant->getKey(),
        'tenant_set' => [$tenant->getKey()],
        'granted_by_id' => $owner->getKey(),
        'granted_reason' => 'deployment owner',
    ]);

    ProjectSshKey::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'created_by_id' => $owner->id,
        'name' => 'laptop-key',
        'key_type' => 'ed25519',
        'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5 laptop',
        'fingerprint' => 'SHA256:abc123def',
        'metadata' => [],
    ]);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
}

it('shows the owner their project with its deployments, stacks, and SSH keys', function (): void {
    [$tenant, $user, $project] = provisionProjectDetailActor();
    seedProjectDetailContents($tenant, $user, $project);

    $this->actingAs($user)
        ->get('/projects/'.$project->getKey())
        ->assertOk()
        ->assertSee($project->name)
        ->assertSee('lab-web-01')          // deployment
        ->assertSee('Two-tier Lab')        // project-local stack
        ->assertSee('laptop-key')          // ssh key
        ->assertSee('SHA256:abc123def');   // fingerprint
});

it('returns 404 for a user who cannot read the project (no existence leak)', function (): void {
    [$tenant, $owner, $project] = provisionProjectDetailActor('Owner');
    seedProjectDetailContents($tenant, $owner, $project);

    [, $outsider] = provisionProjectDetailActor('Outsider');

    $this->actingAs($outsider)
        ->get('/projects/'.$project->getKey())
        ->assertNotFound();
});
