<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

enum RoleBindingScopeType: string
{
    case Global = 'global';
    case MultiTenant = 'multi_tenant';
    case TenantLocal = 'tenant_local';
}
