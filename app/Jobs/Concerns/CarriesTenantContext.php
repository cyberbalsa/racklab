<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

trait CarriesTenantContext
{
    public string $tenantId;

    public function tenantId(): string
    {
        return $this->tenantId;
    }
}
