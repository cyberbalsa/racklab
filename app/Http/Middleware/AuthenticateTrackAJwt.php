<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Jwt\TrackAJwtClaims;
use App\Auth\Jwt\TrackAJwtVerifier;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\RequestGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticateTrackAJwt
{
    public function __construct(
        private TrackAJwtVerifier $jwtVerifier,
        private TenantContextStore $tenantContext,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get(NormalizeTrackBTokenHeader::ATTRIBUTE) !== 'bearer') {
            return $next($request);
        }

        $jwt = $request->bearerToken();

        if (! is_string($jwt) || trim($jwt) === '') {
            throw new AuthenticationException('Missing Track A JWT.');
        }

        $claims = $this->jwtVerifier->verify($jwt);

        /** @var User|null $user */
        $user = User::query()->find($claims->subjectUserId);

        if (! $user instanceof User) {
            throw new AuthenticationException('Track A JWT subject is invalid.');
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()
            ->whereKey($claims->tenantId)
            ->where('is_active', true)
            ->first();

        if (! $tenant instanceof Tenant) {
            throw new AuthenticationException('Track A JWT tenant is invalid.');
        }

        $tenant->makeCurrent();
        $this->tenantContext->set(new TenantContext(activeTenantId: $tenant->id));
        $request->attributes->set(TrackAJwtClaims::REQUEST_ATTRIBUTE, $claims);
        Auth::guard('web')->setUser($user);
        $request->setUserResolver(static fn (): User => $user);
        $this->refreshSanctumRequest($request);

        return $next($request);
    }

    private function refreshSanctumRequest(Request $request): void
    {
        $guard = Auth::guard('sanctum');

        if ($guard instanceof RequestGuard) {
            $guard->setRequest($request);
            $guard->forgetUser();
        }
    }
}
