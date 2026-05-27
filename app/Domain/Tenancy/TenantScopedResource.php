<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

interface TenantScopedResource
{
    public function tenantId(): string;

    public function resourceType(): string;

    public function resourceId(): string;

    public function sharingScope(): SharingScope;

    /**
     * @return list<string>
     */
    public function sharedWithTenantIds(): array;
}
