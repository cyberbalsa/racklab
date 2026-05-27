<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

final class TenantContextStore
{
    private ?TenantContext $context = null;

    public function current(): ?TenantContext
    {
        return $this->context;
    }

    public function set(TenantContext $context): void
    {
        $this->context = $context;
    }

    public function forget(): void
    {
        $this->context = null;
    }
}
