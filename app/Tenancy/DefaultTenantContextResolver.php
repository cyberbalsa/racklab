<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use RuntimeException;

final readonly class DefaultTenantContextResolver
{
    public function __construct(private TenantContextStore $tenantContext) {}

    public function resolve(?User $user = null): TenantContext
    {
        $tenant = $this->tenantForUser($user) ?? $this->defaultTenant();

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException('No active default tenant is configured.');
        }

        $tenant->makeCurrent();

        $context = new TenantContext(activeTenantId: $tenant->id);
        $this->tenantContext->set($context);

        return $context;
    }

    private function tenantForUser(?User $user): ?Tenant
    {
        if (! $user instanceof User) {
            return null;
        }

        /** @var TenantMembership|null $membership */
        $membership = TenantMembership::query()
            ->where('user_id', $user->id)
            ->where('is_primary', true)
            ->first();

        return $membership?->tenant;
    }

    private function defaultTenant(): ?Tenant
    {
        $slug = config('racklab.default_tenant_slug', 'default');

        if (! is_string($slug) || trim($slug) === '') {
            return null;
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        return $tenant;
    }
}
