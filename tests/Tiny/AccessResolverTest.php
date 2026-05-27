<?php

declare(strict_types=1);

use App\Domain\Rbac\Permission;
use App\Domain\Rbac\RolePermissionLookup;
use App\Domain\Tenancy\AccessDenyReason;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\RoleBindingRecord;
use App\Domain\Tenancy\RoleBindingRepository;
use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\SharingScope;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantScopedResource;

it('allows tenant-local access when binding visibility and permission all match', function (): void {
    $decision = resolverWith(
        bindings: [
            new RoleBindingRecord(
                id: 'binding-local-project-owner',
                principalId: 'user-1',
                role: 'project-owner',
                scopeType: RoleBindingScopeType::TenantLocal,
                tenantId: 'tenant-a',
                tenantSet: [],
                resourceType: 'project',
                resourceId: 'project-a',
            ),
        ],
        rolePermissions: ['project-owner' => ['project.read']],
    )->permitted(
        actor: new ActorIdentity('user-1'),
        permission: new Permission('project.read'),
        resource: new FakeTenantResource(
            tenantId: 'tenant-a',
            resourceType: 'project',
            resourceId: 'project-a',
            sharingScope: SharingScope::TenantLocal,
        ),
        context: new TenantContext(activeTenantId: 'tenant-a'),
    );

    expect($decision->allowed)->toBeTrue()
        ->and($decision->denyReason)->toBeNull()
        ->and($decision->provenance)->toBe(['tenant_local']);
});

it('denies cross-tenant access when a binding covers the resource tenant but the resource is not visible', function (): void {
    $decision = resolverWith(
        bindings: [
            new RoleBindingRecord(
                id: 'binding-multi-project-reader',
                principalId: 'user-1',
                role: 'project-reader',
                scopeType: RoleBindingScopeType::MultiTenant,
                tenantId: null,
                tenantSet: ['tenant-b'],
                resourceType: 'project',
                resourceId: 'project-b',
            ),
        ],
        rolePermissions: ['project-reader' => ['project.read']],
    )->permitted(
        actor: new ActorIdentity('user-1'),
        permission: new Permission('project.read'),
        resource: new FakeTenantResource(
            tenantId: 'tenant-b',
            resourceType: 'project',
            resourceId: 'project-b',
            sharingScope: SharingScope::TenantLocal,
        ),
        context: new TenantContext(activeTenantId: 'tenant-a'),
    );

    expect($decision->allowed)->toBeFalse()
        ->and($decision->denyReason)->toBe(AccessDenyReason::ResourceNotVisible)
        ->and($decision->provenance)->toBe(['binding:binding-multi-project-reader:multi_tenant']);
});

it('denies cross-tenant shared resources when no role binding covers the owner tenant', function (): void {
    $decision = resolverWith(
        bindings: [
            new RoleBindingRecord(
                id: 'binding-local-project-reader',
                principalId: 'user-1',
                role: 'project-reader',
                scopeType: RoleBindingScopeType::TenantLocal,
                tenantId: 'tenant-a',
                tenantSet: [],
                resourceType: 'project',
                resourceId: 'project-b',
            ),
        ],
        rolePermissions: ['project-reader' => ['project.read']],
    )->permitted(
        actor: new ActorIdentity('user-1'),
        permission: new Permission('project.read'),
        resource: new FakeTenantResource(
            tenantId: 'tenant-b',
            resourceType: 'project',
            resourceId: 'project-b',
            sharingScope: SharingScope::SharedWithTenants,
            sharedWithTenantIds: ['tenant-a'],
        ),
        context: new TenantContext(activeTenantId: 'tenant-a'),
    );

    expect($decision->allowed)->toBeFalse()
        ->and($decision->denyReason)->toBe(AccessDenyReason::InsufficientScope)
        ->and($decision->provenance)->toBe(['sharing:shared_with_tenants:tenant-b']);
});

it('allows cross-tenant access only when binding visibility and permission all match', function (): void {
    $decision = resolverWith(
        bindings: [
            new RoleBindingRecord(
                id: 'binding-multi-project-reader',
                principalId: 'user-1',
                role: 'project-reader',
                scopeType: RoleBindingScopeType::MultiTenant,
                tenantId: null,
                tenantSet: ['tenant-b'],
                resourceType: 'project',
                resourceId: 'project-b',
            ),
        ],
        rolePermissions: ['project-reader' => ['project.read']],
    )->permitted(
        actor: new ActorIdentity('user-1'),
        permission: new Permission('project.read'),
        resource: new FakeTenantResource(
            tenantId: 'tenant-b',
            resourceType: 'project',
            resourceId: 'project-b',
            sharingScope: SharingScope::SharedWithTenants,
            sharedWithTenantIds: ['tenant-a'],
        ),
        context: new TenantContext(activeTenantId: 'tenant-a'),
    );

    expect($decision->allowed)->toBeTrue()
        ->and($decision->denyReason)->toBeNull()
        ->and($decision->provenance)->toBe([
            'binding:binding-multi-project-reader:multi_tenant',
            'sharing:shared_with_tenants:tenant-b',
        ]);
});

/**
 * @param  list<RoleBindingRecord>  $bindings
 * @param  array<string, list<string>>  $rolePermissions
 */
function resolverWith(array $bindings, array $rolePermissions): AccessResolver
{
    return new AccessResolver(
        roleBindings: new FakeRoleBindingRepository($bindings),
        rolePermissions: new FakeRolePermissionLookup($rolePermissions),
    );
}

final readonly class FakeTenantResource implements TenantScopedResource
{
    /**
     * @param  list<string>  $sharedWithTenantIds
     */
    public function __construct(
        private string $tenantId,
        private string $resourceType,
        private string $resourceId,
        private SharingScope $sharingScope,
        private array $sharedWithTenantIds = [],
    ) {}

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function resourceType(): string
    {
        return $this->resourceType;
    }

    public function resourceId(): string
    {
        return $this->resourceId;
    }

    public function sharingScope(): SharingScope
    {
        return $this->sharingScope;
    }

    public function sharedWithTenantIds(): array
    {
        return $this->sharedWithTenantIds;
    }
}

final readonly class FakeRoleBindingRepository implements RoleBindingRepository
{
    /**
     * @param  list<RoleBindingRecord>  $bindings
     */
    public function __construct(private array $bindings) {}

    public function forActorAndResource(ActorIdentity $actor, TenantScopedResource $resource): array
    {
        return array_values(array_filter(
            $this->bindings,
            static fn (RoleBindingRecord $binding): bool => $binding->principalId === $actor->id
                && $binding->resourceType === $resource->resourceType()
                && $binding->resourceId === $resource->resourceId(),
        ));
    }
}

final readonly class FakeRolePermissionLookup implements RolePermissionLookup
{
    /**
     * @param  array<string, list<string>>  $rolePermissions
     */
    public function __construct(private array $rolePermissions) {}

    public function roleGrants(string $role, Permission $permission): bool
    {
        return in_array($permission->code, $this->rolePermissions[$role] ?? [], strict: true);
    }
}
