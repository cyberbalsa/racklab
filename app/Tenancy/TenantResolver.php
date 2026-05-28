<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;

final readonly class TenantResolver
{
    public function resolve(string $identifier): ?TenantContext
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()
            ->whereKey($identifier)
            ->orWhere('slug', $identifier)
            ->first();

        if ($tenant === null || ! $tenant->is_active) {
            return null;
        }

        $tenant->makeCurrent();

        return new TenantContext(activeTenantId: $tenant->id);
    }
}
