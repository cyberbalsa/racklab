<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Livewire\Catalog\CatalogPublishing;
use App\Models\CatalogVersion;
use App\Models\Project;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{Tenant, User, Project}
 */
function provisionPublishingActor(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Instructor']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}

function makePublishableStack(Tenant $tenant, Project $project): StackDefinition
{
    app(TenantContextStore::class)->set(new TenantContext(activeTenantId: $tenant->getKey()));
    $tenant->makeCurrent();

    /** @var StackDefinition $stack */
    $stack = StackDefinition::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'name' => 'Two-tier Lab',
        'slug' => 'two-tier-lab',
        'scope' => 'project_local',
        'is_reserved_default' => false,
        'definition' => ['provider' => 'fake', 'components' => [['key' => 'vm', 'kind' => 'vm']]],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return $stack;
}

function actAsPublisher(Tenant $tenant, User $user): void
{
    app(TenantContextStore::class)->set(new TenantContext(activeTenantId: $tenant->getKey()));
    $tenant->makeCurrent();
    test()->actingAs($user);
}

it("lists the actor's publishable project-local stacks", function (): void {
    [$tenant, $user, $project] = provisionPublishingActor();
    makePublishableStack($tenant, $project);

    actAsPublisher($tenant, $user);

    Livewire::test(CatalogPublishing::class)
        ->assertOk()
        ->assertSee('Two-tier Lab');
});

it('publishes a selected stack to the catalog', function (): void {
    [$tenant, $user, $project] = provisionPublishingActor();
    $stack = makePublishableStack($tenant, $project);

    actAsPublisher($tenant, $user);

    Livewire::test(CatalogPublishing::class)
        ->set('selectedStackId', $stack->getKey())
        ->set('itemName', 'Two-tier Lab')
        ->set('versionLabel', '1.0.0')
        ->call('publish')
        ->assertHasNoErrors();

    expect(CatalogVersion::query()->where('state', 'published')->count())->toBe(1);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});

it('requires a name and version before publishing', function (): void {
    [$tenant, $user, $project] = provisionPublishingActor();
    $stack = makePublishableStack($tenant, $project);

    actAsPublisher($tenant, $user);

    Livewire::test(CatalogPublishing::class)
        ->set('selectedStackId', $stack->getKey())
        ->set('itemName', '')
        ->set('versionLabel', '')
        ->call('publish')
        ->assertHasErrors(['itemName', 'versionLabel']);

    expect(CatalogVersion::query()->count())->toBe(0);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});
