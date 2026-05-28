<?php

declare(strict_types=1);

use App\Domain\Rbac\Permission;
use App\Domain\Rbac\RolePermissionLookup;
use App\Domain\Tenancy\AccessDenyReason;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\PlatformResource;
use App\Domain\Tenancy\RoleBindingRecord;
use App\Domain\Tenancy\RoleBindingRepository;
use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantScopedResource;

it('allows access when actor has a platform-scope binding granting the permission', function (): void {
    $bindings = [
        new RoleBindingRecord(
            id: 'binding-platform-admin',
            principalId: 'user-1',
            role: 'admin',
            scopeType: RoleBindingScopeType::Global,
            tenantId: null,
            tenantSet: [],
            resourceType: PlatformResource::RESOURCE_TYPE,
            resourceId: PlatformResource::RACKLAB_ID,
        ),
    ];
    $resolver = platformResolverWith(bindings: $bindings, rolePermissions: ['admin' => ['horizon.view']]);

    $decision = $resolver->permittedPlatform(new ActorIdentity('user-1'), new Permission('horizon.view'));

    expect($decision->allowed)->toBeTrue();
    expect($decision->denyReason)->toBeNull();
    expect($decision->provenance)->toBe(['platform:racklab:role=admin']);
});

it('denies access when actor has only tenant-local bindings', function (): void {
    $bindings = [
        new RoleBindingRecord(
            id: 'binding-tenant-local-project-admin',
            principalId: 'user-1',
            role: 'admin',
            scopeType: RoleBindingScopeType::TenantLocal,
            tenantId: 'tenant-a',
            tenantSet: [],
            resourceType: 'project',
            resourceId: 'project-a',
        ),
    ];
    $resolver = platformResolverWith(bindings: $bindings, rolePermissions: ['admin' => ['horizon.view']]);

    $decision = $resolver->permittedPlatform(new ActorIdentity('user-1'), new Permission('horizon.view'));

    expect($decision->allowed)->toBeFalse();
    expect($decision->denyReason)->toBe(AccessDenyReason::InsufficientScope);
});

it('OVER-AUTH REGRESSION GUARD: denies global-scope binding on a project (not the platform resource)', function (): void {
    // A global-scope admin binding on a specific project must NOT grant Horizon.
    // This is the codex v2 P1 finding: permittedPlatform must require the
    // dedicated platform resource, not "any global binding".
    $bindings = [
        new RoleBindingRecord(
            id: 'binding-global-on-project',
            principalId: 'user-1',
            role: 'admin',
            scopeType: RoleBindingScopeType::Global,
            tenantId: null,
            tenantSet: [],
            resourceType: 'project',   // NOT 'platform'
            resourceId: 'project-1',
        ),
    ];
    $resolver = platformResolverWith(bindings: $bindings, rolePermissions: ['admin' => ['horizon.view']]);

    $decision = $resolver->permittedPlatform(new ActorIdentity('user-1'), new Permission('horizon.view'));

    expect($decision->allowed)->toBeFalse();
    expect($decision->denyReason)->toBe(AccessDenyReason::InsufficientScope);
});

it('denies access when platform-scope binding exists but role lacks the permission', function (): void {
    $bindings = [
        new RoleBindingRecord(
            id: 'binding-platform-student',
            principalId: 'user-1',
            role: 'student',
            scopeType: RoleBindingScopeType::Global,
            tenantId: null,
            tenantSet: [],
            resourceType: PlatformResource::RESOURCE_TYPE,
            resourceId: PlatformResource::RACKLAB_ID,
        ),
    ];
    $resolver = platformResolverWith(bindings: $bindings, rolePermissions: ['student' => []]);

    $decision = $resolver->permittedPlatform(new ActorIdentity('user-1'), new Permission('horizon.view'));

    expect($decision->allowed)->toBeFalse();
    expect($decision->denyReason)->toBe(AccessDenyReason::PermissionNotGranted);
});

it('allows access even when actor has additional non-platform bindings', function (): void {
    $bindings = [
        new RoleBindingRecord(
            id: 'binding-tenant-local-project-admin',
            principalId: 'user-1',
            role: 'admin',
            scopeType: RoleBindingScopeType::TenantLocal,
            tenantId: 'tenant-a',
            tenantSet: [],
            resourceType: 'project',
            resourceId: 'project-a',
        ),
        new RoleBindingRecord(
            id: 'binding-platform-admin',
            principalId: 'user-1',
            role: 'admin',
            scopeType: RoleBindingScopeType::Global,
            tenantId: null,
            tenantSet: [],
            resourceType: PlatformResource::RESOURCE_TYPE,
            resourceId: PlatformResource::RACKLAB_ID,
        ),
    ];
    $resolver = platformResolverWith(bindings: $bindings, rolePermissions: ['admin' => ['horizon.view']]);

    $decision = $resolver->permittedPlatform(new ActorIdentity('user-1'), new Permission('horizon.view'));

    expect($decision->allowed)->toBeTrue();
});

/**
 * @param  list<RoleBindingRecord>  $bindings
 * @param  array<string, list<string>>  $rolePermissions
 */
function platformResolverWith(array $bindings, array $rolePermissions): AccessResolver
{
    $roleBindings = new readonly class($bindings) implements RoleBindingRepository
    {
        /**
         * @param  list<RoleBindingRecord>  $bindings
         */
        public function __construct(private array $bindings) {}

        public function forActorAndResource(ActorIdentity $actor, TenantScopedResource $resource): array
        {
            // Not exercised in platform tests; keep aligned with the resource filter
            return array_values(array_filter(
                $this->bindings,
                static fn (RoleBindingRecord $b): bool => $b->principalId === $actor->id
                    && $b->resourceType === $resource->resourceType()
                    && $b->resourceId === $resource->resourceId(),
            ));
        }

        public function forActor(ActorIdentity $actor): array
        {
            return array_values(array_filter(
                $this->bindings,
                static fn (RoleBindingRecord $b): bool => $b->principalId === $actor->id,
            ));
        }
    };

    $rolePermissionLookup = new readonly class($rolePermissions) implements RolePermissionLookup
    {
        /**
         * @param  array<string, list<string>>  $rolePermissions
         */
        public function __construct(private array $rolePermissions) {}

        public function roleGrants(string $role, Permission $permission): bool
        {
            return in_array($permission->code, $this->rolePermissions[$role] ?? [], strict: true);
        }
    };

    return new AccessResolver(
        roleBindings: $roleBindings,
        rolePermissions: $rolePermissionLookup,
    );
}
