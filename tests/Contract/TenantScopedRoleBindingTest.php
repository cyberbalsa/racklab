<?php

declare(strict_types=1);

use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\CatalogItem;
use App\Models\RoleBinding;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\EloquentRoleBindingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns tenant-scoped bindings for any resource owned by that tenant', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create();

    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $user->id,
        'role' => 'tenant_member',
        'resource_type' => 'tenant',
        'resource_id' => $tenant->getKey(),
        'scope_type' => RoleBindingScopeType::TenantLocal,
        'tenant_id' => $tenant->getKey(),
        'tenant_set' => [],
        'granted_by_id' => $user->getKey(),
        'granted_reason' => 'tenant membership',
    ]);

    $item = CatalogItem::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Ubuntu',
        'slug' => 'ubuntu',
        'description' => null,
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    $records = app(EloquentRoleBindingRepository::class)
        ->forActorAndResource(new ActorIdentity((string) $user->id), $item);

    expect($records)->toHaveCount(1)
        ->and($records[0]->role)->toBe('tenant_member')
        ->and($records[0]->coversTenant($tenant->getKey()))->toBeTrue();

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});

it("does not return another tenant's tenant-scoped bindings for a resource", function (): void {
    $tenantA = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
    $user = User::factory()->create();

    // Member of tenant B only.
    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $user->id,
        'role' => 'tenant_member',
        'resource_type' => 'tenant',
        'resource_id' => $tenantB->getKey(),
        'scope_type' => RoleBindingScopeType::TenantLocal,
        'tenant_id' => $tenantB->getKey(),
        'tenant_set' => [],
        'granted_by_id' => $user->getKey(),
        'granted_reason' => 'tenant membership',
    ]);

    $context = new TenantContext(activeTenantId: $tenantA->getKey());
    app(TenantContextStore::class)->set($context);
    $tenantA->makeCurrent();

    $item = CatalogItem::query()->create([
        'tenant_id' => $tenantA->getKey(),
        'name' => 'Ubuntu',
        'slug' => 'ubuntu',
        'description' => null,
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    $records = app(EloquentRoleBindingRepository::class)
        ->forActorAndResource(new ActorIdentity((string) $user->id), $item);

    expect($records)->toBe([]);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});
