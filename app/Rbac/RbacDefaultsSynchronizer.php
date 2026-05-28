<?php

declare(strict_types=1);

namespace App\Rbac;

use App\Domain\Rbac\DefaultRoleCatalog;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final readonly class RbacDefaultsSynchronizer
{
    public function __construct(private PermissionRegistrar $permissionRegistrar) {}

    public function sync(string $guardName = 'web'): RbacSyncResult
    {
        return DB::transaction(function () use ($guardName): RbacSyncResult {
            $rolesCreated = 0;
            $permissionsCreated = 0;
            $rolePermissionEdgesSynced = 0;

            foreach ($this->permissionNames() as $permissionName) {
                $permission = Permission::findOrCreate($permissionName, $guardName);

                if ($permission->wasRecentlyCreated) {
                    $permissionsCreated++;
                }
            }

            $this->permissionRegistrar->forgetCachedPermissions();

            foreach (DefaultRoleCatalog::permissionsByRole() as $roleName => $permissionNames) {
                $role = Role::findOrCreate($roleName, $guardName);

                if ($role->wasRecentlyCreated) {
                    $rolesCreated++;
                }

                $role->syncPermissions($permissionNames);
                $rolePermissionEdgesSynced += count($permissionNames);
            }

            $this->permissionRegistrar->forgetCachedPermissions();

            return new RbacSyncResult(
                rolesCreated: $rolesCreated,
                permissionsCreated: $permissionsCreated,
                rolePermissionEdgesSynced: $rolePermissionEdgesSynced,
            );
        });
    }

    /**
     * @return list<string>
     */
    private function permissionNames(): array
    {
        $permissionNames = [];

        foreach (DefaultRoleCatalog::permissionsByRole() as $rolePermissionNames) {
            foreach ($rolePermissionNames as $permissionName) {
                $permissionNames[] = $permissionName;
            }
        }

        $permissionNames = array_values(array_unique($permissionNames));
        sort($permissionNames);

        return $permissionNames;
    }
}
