<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

final readonly class TenantAccessResource implements TenantScopedResource
{
    public function __construct(private string $tenantId) {}

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function resourceType(): string
    {
        return 'tenant';
    }

    public function resourceId(): string
    {
        return $this->tenantId;
    }

    public function sharingScope(): SharingScope
    {
        return SharingScope::TenantLocal;
    }

    /**
     * @return list<string>
     */
    public function sharedWithTenantIds(): array
    {
        return [];
    }
}
