<?php

declare(strict_types=1);

use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\CatalogItem;
use App\Models\CatalogVersion;
use App\Models\Project;
use App\Models\RoleBinding;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('lists published catalog items and deploys a published catalog version', function (): void {
    [$tenant, $user, $project] = provisionCatalogUserProject();
    [$item, $version] = createPublishedCatalogVersion($tenant, $user, grantRead: true);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/catalog/items')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $item->getKey())
        ->assertJsonPath('data.0.current_version.id', $version->getKey());

    $this->getJson('/api/v1/catalog/items/'.$item->getKey().'/versions/'.$version->version)
        ->assertOk()
        ->assertJsonPath('data.id', $version->getKey())
        ->assertJsonPath('data.stack_definition.name', 'Ubuntu single VM');

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'catalog_version_id' => $version->getKey(),
        'operation' => 'deploy',
        'idempotency_key' => 'deploy-catalog-version',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Ubuntu single VM')
        ->assertJsonPath('data.stack_definition_id', $version->stack_definition_id)
        ->assertJsonPath('data.state', 'running');
});

it('lets any tenant member read and deploy a tenant-local published catalog item without an item-specific grant', function (): void {
    [$tenant, $user, $project] = provisionCatalogUserProject();
    [$item, $version] = createPublishedCatalogVersion($tenant, $user, grantRead: false);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/catalog/items')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $item->getKey());

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'catalog_version_id' => $version->getKey(),
        'operation' => 'deploy',
        'idempotency_key' => 'deploy-tenant-member-catalog-version',
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'running');
});

it('does not expose or deploy catalog versions owned by another tenant', function (): void {
    [, $user, $project] = provisionCatalogUserProject();

    $otherTenant = Tenant::query()->create(['name' => 'Other Tenant', 'slug' => 'other']);
    [, $version] = createPublishedCatalogVersion($otherTenant, $user, grantRead: false);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/catalog/items')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'catalog_version_id' => $version->getKey(),
        'operation' => 'deploy',
        'idempotency_key' => 'deploy-cross-tenant-catalog-version',
    ])->assertNotFound();
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionCatalogUserProject(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
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
function createPublishedCatalogVersion(Tenant $tenant, User $user, bool $grantRead): array
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
        'definition' => ['components' => [['key' => 'vm', 'kind' => 'vm']]],
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

    if ($grantRead) {
        RoleBinding::query()->create([
            'principal_type' => 'user',
            'principal_id' => (string) $user->id,
            'role' => 'student',
            'resource_type' => $item->resourceType(),
            'resource_id' => $item->resourceId(),
            'scope_type' => RoleBindingScopeType::TenantLocal,
            'tenant_id' => $tenant->getKey(),
            'tenant_set' => [$tenant->getKey()],
            'granted_by_id' => $user->getKey(),
            'granted_reason' => 'catalog smoke access',
        ]);
    }

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$item, $version];
}
