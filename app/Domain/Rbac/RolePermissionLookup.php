<?php

declare(strict_types=1);

namespace App\Domain\Rbac;

interface RolePermissionLookup
{
    public function roleGrants(string $role, Permission $permission): bool;
}
