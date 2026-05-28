<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\CarriesTenantContext;
use App\Jobs\Contracts\TenantAwareJob;

abstract class TenantAwareQueuedJob implements TenantAwareJob
{
    use CarriesTenantContext;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
    }
}
