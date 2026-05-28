<?php

declare(strict_types=1);

namespace App\Rbac;

final readonly class RbacSyncResult
{
    public function __construct(
        public int $rolesCreated,
        public int $permissionsCreated,
        public int $rolePermissionEdgesSynced,
    ) {}
}
