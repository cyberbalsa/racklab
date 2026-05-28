<?php

declare(strict_types=1);

use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Deployment;
use App\Models\Project;
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
function provisionLabelActor(string $name): array
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

function makeOwnedDeployment(Tenant $tenant, User $owner, Project $project, string $name = 'web-01'): Deployment
{
    app(TenantContextStore::class)->set(new TenantContext(activeTenantId: $tenant->getKey()));
    $tenant->makeCurrent();

    $stack = StackDefinition::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'name' => 'Stack',
        'slug' => 'stack-'.$name,
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
        'name' => $name,
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

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return $deployment;
}

it('lets the owner set normalized labels on a deployment', function (): void {
    [$tenant, $user, $project] = provisionLabelActor('Owner');
    $deployment = makeOwnedDeployment($tenant, $user, $project);

    $this->actingAs($user)
        ->post('/deployments/'.$deployment->getKey().'/labels', [
            'labels' => 'Week 1,  week 1 , exam-prep , ',
        ])
        ->assertRedirect();

    $deployment->refresh();

    // Normalized: trimmed, lowercased, de-duplicated, blanks dropped.
    expect($deployment->labels)->toBe(['week 1', 'exam-prep']);
});

it('does not let a non-owner label a deployment', function (): void {
    [$tenant, $owner, $project] = provisionLabelActor('Owner');
    $deployment = makeOwnedDeployment($tenant, $owner, $project);

    [, $outsider] = provisionLabelActor('Outsider');

    $this->actingAs($outsider)
        ->post('/deployments/'.$deployment->getKey().'/labels', ['labels' => 'sneaky'])
        ->assertNotFound();

    $deployment->refresh();
    expect($deployment->labels ?? [])->toBe([]);
});

it('shows deployment labels and filters the dashboard by label', function (): void {
    [$tenant, $user, $project] = provisionLabelActor('Owner');
    $a = makeOwnedDeployment($tenant, $user, $project, 'web-01');
    $b = makeOwnedDeployment($tenant, $user, $project, 'db-01');

    app(TenantContextStore::class)->set(new TenantContext(activeTenantId: $tenant->getKey()));
    $tenant->makeCurrent();
    $a->update(['labels' => ['exam-prep']]);
    $b->update(['labels' => ['scratch']]);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertSee('exam-prep')
        ->assertSee('scratch');

    // Filtered view shows only the matching deployment.
    $this->actingAs($user)->get('/dashboard?label=exam-prep')
        ->assertOk()
        ->assertSee('web-01')
        ->assertDontSee('db-01');
});
