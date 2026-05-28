<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Jobs\Contracts\TenantAwareJob;
use App\Models\Tenant;
use Closure;

/**
 * Job middleware that re-establishes the tenant context every time a
 * TenantAwareJob is dispatched by a worker.
 *
 * Manages BOTH RackLab's TenantContextStore (used by domain code) AND
 * Spatie's current-tenant slot (used by Eloquent scopes + downstream
 * Spatie integrations). Without the Spatie call, a Horizon worker that
 * processes Job A on tenant X would leave Spatie's current tenant set
 * to X when Job B on tenant Y starts — Y's queries would briefly see X's
 * tenant scope. This is the codex v1 P1 finding.
 *
 * See docs/superpowers/specs/2026-05-28-horizon-and-supply-chain-design.md §7.
 */
final readonly class BindTenantContext
{
    public function __construct(private TenantContextStore $tenantContext) {}

    /**
     * @param  Closure(TenantAwareJob): mixed  $next
     */
    public function handle(TenantAwareJob $job, Closure $next): mixed
    {
        $this->tenantContext->forget();
        Tenant::forgetCurrent();

        $tenant = Tenant::query()->findOrFail($job->tenantId());
        $this->tenantContext->set(new TenantContext(activeTenantId: $tenant->id));
        $tenant->makeCurrent();

        try {
            return $next($job);
        } finally {
            $this->tenantContext->forget();
            Tenant::forgetCurrent();
        }
    }
}
