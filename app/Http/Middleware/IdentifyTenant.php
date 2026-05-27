<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class IdentifyTenant
{
    public function __construct(private TenantContextStore $tenantContext) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $this->tenantIdFromRequest($request);

        if ($tenantId !== null) {
            $this->tenantContext->set(new TenantContext(activeTenantId: $tenantId));
        }

        return $next($request);
    }

    private function tenantIdFromRequest(Request $request): ?string
    {
        $headerTenant = trim((string) $request->headers->get('X-RackLab-Tenant', ''));

        if ($headerTenant !== '') {
            return $headerTenant;
        }

        $routeTenant = $request->route('tenant');

        if (is_string($routeTenant) && trim($routeTenant) !== '') {
            return trim($routeTenant);
        }

        return null;
    }
}
