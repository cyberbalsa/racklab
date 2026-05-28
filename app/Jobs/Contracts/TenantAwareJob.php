<?php

declare(strict_types=1);

namespace App\Jobs\Contracts;

interface TenantAwareJob
{
    public function tenantId(): string;
}
