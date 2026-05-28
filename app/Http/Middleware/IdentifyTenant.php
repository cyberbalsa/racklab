<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class IdentifyTenant
{
    public function __construct(
        private TenantContextStore $tenantContext,
        private TenantResolver $tenantResolver,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantIdentifier = $this->tenantIdentifierFromRequest($request);

        if ($tenantIdentifier !== null) {
            $context = $this->tenantResolver->resolve($tenantIdentifier);

            if (! $context instanceof TenantContext) {
                throw new NotFoundHttpException('Tenant not found.');
            }

            $this->tenantContext->set($context);
        }

        return $next($request);
    }

    private function tenantIdentifierFromRequest(Request $request): ?string
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
