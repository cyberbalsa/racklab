<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Model;

/**
 * @template TResource of Model&TenantScopedResource
 */
final readonly class CrossTenantFetchResult
{
    /**
     * @param  TResource  $resource
     * @param  list<string>  $provenance
     */
    public function __construct(
        public Model&TenantScopedResource $resource,
        public array $provenance,
    ) {}
}
