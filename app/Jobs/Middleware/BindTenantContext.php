<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Jobs\Contracts\TenantAwareJob;
use Closure;

final readonly class BindTenantContext
{
    public function __construct(private TenantContextStore $tenantContext) {}

    /**
     * @param  Closure(TenantAwareJob): mixed  $next
     */
    public function handle(TenantAwareJob $job, Closure $next): mixed
    {
        $this->tenantContext->forget();
        $this->tenantContext->set(new TenantContext(activeTenantId: $job->tenantId()));

        try {
            return $next($job);
        } finally {
            $this->tenantContext->forget();
        }
    }
}
