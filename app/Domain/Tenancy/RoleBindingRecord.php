<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use InvalidArgumentException;

final readonly class RoleBindingRecord
{
    /**
     * @param  list<string>  $tenantSet
     */
    public function __construct(
        public string $id,
        public string $principalId,
        public string $role,
        public RoleBindingScopeType $scopeType,
        public ?string $tenantId,
        public array $tenantSet,
        public string $resourceType,
        public string $resourceId,
    ) {
        $this->assertNotBlank($id, 'Role binding id');
        $this->assertNotBlank($principalId, 'Role binding principal id');
        $this->assertNotBlank($role, 'Role binding role');
        $this->assertNotBlank($resourceType, 'Role binding resource type');
        $this->assertNotBlank($resourceId, 'Role binding resource id');

        if ($scopeType === RoleBindingScopeType::TenantLocal && ($tenantId === null || trim($tenantId) === '')) {
            throw new InvalidArgumentException('Tenant-local role bindings require a tenant id.');
        }

        if ($scopeType === RoleBindingScopeType::MultiTenant && $tenantSet === []) {
            throw new InvalidArgumentException('Multi-tenant role bindings require a non-empty tenant set.');
        }
    }

    public function coversTenant(string $tenantId): bool
    {
        return match ($this->scopeType) {
            RoleBindingScopeType::TenantLocal => $this->tenantId === $tenantId,
            RoleBindingScopeType::MultiTenant => in_array($tenantId, $this->tenantSet, strict: true),
            RoleBindingScopeType::Global => true,
        };
    }

    private function assertNotBlank(string $value, string $label): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException($label.' must not be blank.');
        }
    }
}
