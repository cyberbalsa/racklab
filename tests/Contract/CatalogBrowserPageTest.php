<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Livewire\Catalog\CatalogBrowser;
use App\Models\CatalogItem;
use App\Models\CatalogVersion;
use App\Models\Deployment;
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
function provisionCatalogBrowserActor(string $slug = 'default'): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    /** @var Tenant|null $tenant */
    $tenant = Tenant::query()->where('slug', $slug)->first();
    $tenant ??= Tenant::query()->create(['name' => ucfirst($slug).' Tenant', 'slug' => $slug]);

    $user = User::factory()->create(['name' => 'Catalog Student']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}

/**
 * @return array{CatalogItem, CatalogVersion}
 */
function publishCatalogBrowserItem(Tenant $tenant): array
{
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $stack = StackDefinition::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => null,
        'name' => 'Ubuntu single VM',
        'slug' => 'ubuntu-single-vm',
        'scope' => 'catalog',
        'is_reserved_default' => false,
        'definition' => ['provider' => 'fake', 'components' => [['key' => 'vm', 'kind' => 'vm']]],
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);
    $item = CatalogItem::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Ubuntu',
        'slug' => 'ubuntu',
        'description' => 'A published fake-provider VM.',
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);
    $version = CatalogVersion::query()->create([
        'tenant_id' => $tenant->getKey(),
        'catalog_item_id' => $item->getKey(),
        'stack_definition_id' => $stack->getKey(),
        'version' => '1.0.0',
        'state' => 'published',
        'published_at' => now(),
        'summary' => 'Initial version.',
    ]);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$item, $version];
}

/**
 * Bind tenant + auth context the way the route middleware would, so the
 * Livewire component (driven directly) sees the same state as a real request.
 */
function actAsCatalogBrowser(Tenant $tenant, User $user): void
{
    app(TenantContextStore::class)->set(new TenantContext(activeTenantId: $tenant->getKey()));
    $tenant->makeCurrent();
    test()->actingAs($user);
}

it('lists tenant-readable published catalog items for a member', function (): void {
    [$tenant, $user] = provisionCatalogBrowserActor();
    [$item] = publishCatalogBrowserItem($tenant);

    actAsCatalogBrowser($tenant, $user);

    Livewire::test(CatalogBrowser::class)
        ->assertOk()
        ->assertSee('Ubuntu')
        ->assertSee($item->description ?? '');
});

it('does not list catalog items owned by another tenant', function (): void {
    [$tenant, $user] = provisionCatalogBrowserActor();
    $otherTenant = Tenant::query()->create(['name' => 'Other', 'slug' => 'other']);
    [$otherItem] = publishCatalogBrowserItem($otherTenant);

    actAsCatalogBrowser($tenant, $user);

    Livewire::test(CatalogBrowser::class)
        ->assertDontSee($otherItem->getKey());
});

it('deploys a selected catalog version into the chosen project', function (): void {
    [$tenant, $user, $project] = provisionCatalogBrowserActor();
    [, $version] = publishCatalogBrowserItem($tenant);

    actAsCatalogBrowser($tenant, $user);

    Livewire::test(CatalogBrowser::class)
        ->set('selectedProjectId', $project->getKey())
        ->call('deploy', $version->getKey())
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $deployment = Deployment::query()
        ->where('project_id', $project->getKey())
        ->where('stack_definition_id', $version->stack_definition_id)
        ->first();

    expect($deployment)->not->toBeNull()
        ->and($deployment?->state)->toBe('running');

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});

it('refuses to deploy a catalog version owned by another tenant', function (): void {
    [$tenant, $user, $project] = provisionCatalogBrowserActor();
    $otherTenant = Tenant::query()->create(['name' => 'Other', 'slug' => 'other']);
    [, $otherVersion] = publishCatalogBrowserItem($otherTenant);

    actAsCatalogBrowser($tenant, $user);

    Livewire::test(CatalogBrowser::class)
        ->set('selectedProjectId', $project->getKey())
        ->call('deploy', $otherVersion->getKey());

    expect(Deployment::query()->count())->toBe(0);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});
