<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContextStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class SetTenantContextForOctane
{
    public function __construct(private TenantContextStore $tenantContext) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->tenantContext->forget();

        return $next($request);
    }

    public function terminate(): void
    {
        $this->tenantContext->forget();
    }
}
