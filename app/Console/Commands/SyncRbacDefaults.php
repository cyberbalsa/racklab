<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Console\Command;

final class SyncRbacDefaults extends Command
{
    protected $signature = 'racklab:sync-rbac-defaults';

    protected $description = 'Synchronize RackLab default roles and permissions into the database.';

    public function handle(RbacDefaultsSynchronizer $synchronizer): int
    {
        $result = $synchronizer->sync();

        $this->components->info(sprintf(
            'RBAC defaults synced: %d roles created, %d permissions created, %d role-permission edges applied.',
            $result->rolesCreated,
            $result->permissionsCreated,
            $result->rolePermissionEdgesSynced,
        ));

        return self::SUCCESS;
    }
}
