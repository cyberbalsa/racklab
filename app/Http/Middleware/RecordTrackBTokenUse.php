<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Audit\AuditEventWriter;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\TokenGrant;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final readonly class RecordTrackBTokenUse
{
    public function __construct(
        private TenantContextStore $tenantContext,
        private AuditEventWriter $auditEvents,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get(NormalizeTrackBTokenHeader::ATTRIBUTE) !== 'token') {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User || ! $user->currentAccessToken() instanceof PersonalAccessToken) {
            throw new AuthenticationException;
        }

        $accessToken = $user->currentAccessToken();
        $context = $this->tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new AuthenticationException;
        }

        /** @var TokenGrant|null $grant */
        $grant = TokenGrant::query()
            ->where('sanctum_token_id', $accessToken->getKey())
            ->first();

        if (! $grant instanceof TokenGrant || $grant->revoked_at !== null) {
            throw new AuthenticationException;
        }

        $grant->forceFill(['last_used_at' => now()])->save();

        $this->auditEvents->append([
            'event_type' => 'token.grant',
            'action' => 'use',
            'result' => 'allowed',
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => $grant->resourceType(),
            'resource_id' => $grant->resourceId(),
            'resource_tenant' => $grant->tenant_id,
            'target_tenant_set' => [$grant->tenant_id],
            'effective_permissions' => $grant->abilities,
            'source_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'track' => 'pat',
                'path' => $request->path(),
            ],
        ]);

        return $next($request);
    }
}
