<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

enum SharingScope: string
{
    case Global = 'global';
    case SharedWithTenants = 'shared_with_tenants';
    case TenantLocal = 'tenant_local';
}
