<?php

declare(strict_types=1);

use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Deployment;
use App\Models\RoleBinding;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;

uses(DatabaseMigrations::class);

it('navigates dashboard → project detail → script library by clicking links', function (): void {
    [$tenant, $user, $project] = provisionProjectScreensActor();

    $this->browse(function (Browser $browser) use ($user, $project): void {
        $browser
            ->loginAs($user)
            ->visit('/dashboard')
            ->waitForText('Projects')
            ->click('@project-open-'.$project->getKey())
            ->waitForLocation('/projects/'.$project->getKey())
            ->waitForText('lab-web-01')        // deployment on the detail page
            ->assertSee('Two-tier Lab')        // project-local stack
            ->click('@project-scripts-link')
            ->waitForLocation('/projects/'.$project->getKey().'/scripts')
            ->waitForText('Script library');
    });
});

/**
 * @return array{Tenant, User, App\Models\Project}
 */
function provisionProjectScreensActor(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Screens User', 'email' => 'screens@example.test']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

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
        'requested_by_id' => $user->id,
        'name' => 'lab-web-01',
        'state' => 'running',
        'provider' => 'fake',
        'metadata' => [],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $user->id,
        'role' => 'student',
        'resource_type' => $deployment->resourceType(),
        'resource_id' => $deployment->resourceId(),
        'scope_type' => RoleBindingScopeType::TenantLocal,
        'tenant_id' => $tenant->getKey(),
        'tenant_set' => [$tenant->getKey()],
        'granted_by_id' => $user->getKey(),
        'granted_reason' => 'deployment owner',
    ]);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}
