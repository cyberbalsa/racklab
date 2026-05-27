<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use InvalidArgumentException;

final readonly class TenantContext
{
    public function __construct(public string $activeTenantId)
    {
        if (trim($activeTenantId) === '') {
            throw new InvalidArgumentException('Active tenant id must not be blank.');
        }
    }
}
