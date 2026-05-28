<?php

declare(strict_types=1);

namespace App\Rbac;

use App\Domain\Rbac\Permission;
use App\Domain\Rbac\RolePermissionLookup;
use Spatie\Permission\Models\Role;

final readonly class EloquentRolePermissionLookup implements RolePermissionLookup
{
    public function roleGrants(string $role, Permission $permission): bool
    {
        return Role::query()
            ->where('name', $role)
            ->where('guard_name', 'web')
            ->whereHas(
                'permissions',
                static fn ($query) => $query
                    ->where('name', $permission->code)
                    ->where('guard_name', 'web'),
            )
            ->exists();
    }
}
