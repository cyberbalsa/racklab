<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Rbac\Permission;
use App\Domain\Rbac\RolePermissionLookup;

final readonly class AccessResolver
{
    public function __construct(
        private RoleBindingRepository $roleBindings,
        private RolePermissionLookup $rolePermissions,
    ) {}

    public function permitted(
        ActorIdentity $actor,
        Permission $permission,
        TenantScopedResource $resource,
        TenantContext $context,
    ): AccessDecision {
        $bindings = $this->roleBindings->forActorAndResource($actor, $resource);
        $scopeCoveringBindings = array_values(array_filter(
            $bindings,
            static fn (RoleBindingRecord $binding): bool => $binding->coversTenant($resource->tenantId()),
        ));

        $bindingProvenance = $this->bindingProvenance($scopeCoveringBindings, $resource, $context);
        $visible = $this->visibilityIncludesActor($resource, $context);
        $visibilityProvenance = $visible ? $this->visibilityProvenance($resource, $context) : [];

        if ($scopeCoveringBindings === []) {
            return new AccessDecision(
                allowed: false,
                denyReason: AccessDenyReason::InsufficientScope,
                provenance: $this->uniqueProvenance([...$bindingProvenance, ...$visibilityProvenance]),
            );
        }

        if (! $visible) {
            return new AccessDecision(
                allowed: false,
                denyReason: AccessDenyReason::ResourceNotVisible,
                provenance: $bindingProvenance,
            );
        }

        $grantingBindings = array_values(array_filter(
            $scopeCoveringBindings,
            fn (RoleBindingRecord $binding): bool => $this->rolePermissions->roleGrants($binding->role, $permission),
        ));

        if ($grantingBindings === []) {
            return new AccessDecision(
                allowed: false,
                denyReason: AccessDenyReason::PermissionNotGranted,
                provenance: $this->uniqueProvenance([...$bindingProvenance, ...$visibilityProvenance]),
            );
        }

        return new AccessDecision(
            allowed: true,
            denyReason: null,
            provenance: $this->uniqueProvenance([
                ...$this->bindingProvenance($grantingBindings, $resource, $context),
                ...$visibilityProvenance,
            ]),
        );
    }

    private function visibilityIncludesActor(TenantScopedResource $resource, TenantContext $context): bool
    {
        if ($resource->tenantId() === $context->activeTenantId) {
            return true;
        }

        return match ($resource->sharingScope()) {
            SharingScope::TenantLocal => false,
            SharingScope::SharedWithTenants => in_array(
                $context->activeTenantId,
                $resource->sharedWithTenantIds(),
                strict: true,
            ),
            SharingScope::Global => true,
        };
    }

    /**
     * @param  list<RoleBindingRecord>  $bindings
     * @return list<string>
     */
    private function bindingProvenance(
        array $bindings,
        TenantScopedResource $resource,
        TenantContext $context,
    ): array {
        return array_map(
            static function (RoleBindingRecord $binding) use ($resource, $context): string {
                if (
                    $resource->tenantId() === $context->activeTenantId
                    && $binding->scopeType === RoleBindingScopeType::TenantLocal
                ) {
                    return 'tenant_local';
                }

                return sprintf('binding:%s:%s', $binding->id, $binding->scopeType->value);
            },
            $bindings,
        );
    }

    /**
     * @return list<string>
     */
    private function visibilityProvenance(TenantScopedResource $resource, TenantContext $context): array
    {
        if ($resource->tenantId() === $context->activeTenantId) {
            return ['tenant_local'];
        }

        return [sprintf('sharing:%s:%s', $resource->sharingScope()->value, $resource->tenantId())];
    }

    /**
     * @param  list<string>  $provenance
     * @return list<string>
     */
    private function uniqueProvenance(array $provenance): array
    {
        return array_values(array_unique($provenance));
    }

    /**
     * Authorize a platform-wide capability.
     *
     * Platform resources (Horizon dashboard, future admin endpoints) have no
     * meaningful tenant, so the tenant/visibility predicates from
     * `permitted()` don't apply. Authorization here requires a role binding
     * with `scope_type=Global` targeting the dedicated platform resource
     * `(PlatformResource::RESOURCE_TYPE, PlatformResource::RACKLAB_ID)` whose
     * role grants the requested permission.
     *
     * A global-scope binding on any other resource (e.g. a specific project)
     * does NOT carry over to the platform — that would be over-authorization.
     */
    public function permittedPlatform(
        ActorIdentity $actor,
        Permission $permission,
    ): AccessDecision {
        $platformBindings = array_values(array_filter(
            $this->roleBindings->forActor($actor),
            static fn (RoleBindingRecord $binding): bool => $binding->scopeType === RoleBindingScopeType::Global
                && $binding->resourceType === PlatformResource::RESOURCE_TYPE
                && $binding->resourceId === PlatformResource::RACKLAB_ID,
        ));

        if ($platformBindings === []) {
            return new AccessDecision(
                allowed: false,
                denyReason: AccessDenyReason::InsufficientScope,
                provenance: [],
            );
        }

        $grantingBindings = array_values(array_filter(
            $platformBindings,
            fn (RoleBindingRecord $binding): bool => $this->rolePermissions->roleGrants($binding->role, $permission),
        ));

        if ($grantingBindings === []) {
            return new AccessDecision(
                allowed: false,
                denyReason: AccessDenyReason::PermissionNotGranted,
                provenance: [],
            );
        }

        return new AccessDecision(
            allowed: true,
            denyReason: null,
            provenance: array_map(
                static fn (RoleBindingRecord $b): string => sprintf('platform:racklab:role=%s', $b->role),
                $grantingBindings,
            ),
        );
    }
}
