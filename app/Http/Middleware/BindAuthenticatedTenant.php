<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\User;
use App\Tenancy\DefaultTenantContextResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class BindAuthenticatedTenant
{
    public function __construct(
        private TenantContextStore $tenantContext,
        private DefaultTenantContextResolver $defaultTenant,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tenantContext->current() instanceof TenantContext) {
            $user = $request->user();

            if ($user instanceof User) {
                $this->defaultTenant->resolve($user);
            }
        }

        return $next($request);
    }
}
