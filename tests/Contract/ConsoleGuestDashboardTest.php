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

it('shows a console guest the shared deployment read-only, without management controls', function (): void {
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $owner = User::factory()->create(['name' => 'Owner']);
    $guest = User::factory()->create(['name' => 'Guest']);

    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    // The guest is a provisioned tenant member (gets their own personal project).
    app(PersonalProjectProvisioner::class)->ensureFor($guest, $context);

    $project = Project::query()->create([
        'tenant_id' => $tenant->getKey(), 'name' => 'P', 'slug' => 'p-owner',
        'sharing_scope' => 'tenant_local', 'shared_with_tenants' => [],
    ]);
    $stack = StackDefinition::query()->create([
        'tenant_id' => $tenant->getKey(), 'project_id' => $project->getKey(), 'name' => 'S', 'slug' => 's',
        'scope' => 'project_local', 'is_reserved_default' => false,
        'definition' => ['provider' => 'fake', 'components' => []], 'sharing_scope' => 'tenant_local', 'shared_with_tenants' => [],
    ]);
    /** @var Deployment $deployment */
    $deployment = Deployment::query()->create([
        'tenant_id' => $tenant->getKey(), 'project_id' => $project->getKey(), 'stack_definition_id' => $stack->getKey(),
        'requested_by_id' => $owner->id, 'name' => 'shared-lab-vm', 'state' => 'running', 'provider' => 'fake',
        'metadata' => [], 'sharing_scope' => 'tenant_local', 'shared_with_tenants' => [],
    ]);

    // The guest holds only a console_guest binding on the deployment.
    RoleBinding::query()->create([
        'principal_type' => 'user', 'principal_id' => (string) $guest->id, 'role' => 'console_guest',
        'resource_type' => $deployment->resourceType(), 'resource_id' => $deployment->resourceId(),
        'scope_type' => RoleBindingScopeType::TenantLocal, 'tenant_id' => $tenant->getKey(),
        'tenant_set' => [$tenant->getKey()], 'granted_by_id' => $owner->getKey(), 'granted_reason' => 'console share',
    ]);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    $this->actingAs($guest)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('shared-lab-vm')                               // read: visible
        ->assertDontSee('dusk="release-'.$deployment->getKey().'"', escape: false)        // no release control
        ->assertDontSee('dusk="save-labels-'.$deployment->getKey().'"', escape: false);   // no label editor
});
