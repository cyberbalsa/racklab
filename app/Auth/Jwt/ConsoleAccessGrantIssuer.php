<?php

declare(strict_types=1);

namespace App\Auth\Jwt;

use App\Audit\AuditEventWriter;
use App\Domain\Console\ConsoleAccessGrant;
use App\Domain\Console\ConsoleKind;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Models\Deployment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class ConsoleAccessGrantIssuer
{
    public const string CONNECT_PERMISSION = 'deployment.console.connect';

    public const string TOKEN_TYPE = 'console';

    public function __construct(
        private AccessResolver $accessResolver,
        private AuditEventWriter $auditEvents,
        private TrackAIssuer $trackAIssuer,
    ) {}

    public function issue(
        User $issuer,
        TenantContext $context,
        Deployment $deployment,
        ConsoleKind $consoleKind,
    ): ConsoleAccessGrantIssue {
        $actor = new ActorIdentity((string) $issuer->id);
        $decision = $this->accessResolver->permitted(
            $actor,
            new Permission(self::CONNECT_PERMISSION),
            $deployment,
            $context,
        );

        if (! $decision->allowed) {
            $this->auditDenied($issuer, $context, $deployment, $consoleKind, $decision->denyReason?->value);

            throw new AuthorizationException(
                sprintf('Connecting a %s console requires the %s permission for this deployment.', $consoleKind->value, self::CONNECT_PERMISSION)
            );
        }

        $ttl = $this->grantTtlSeconds();
        $expiresAt = CarbonImmutable::now()->addSeconds($ttl);

        $jwtIssue = $this->trackAIssuer->issue(
            issuer: $issuer,
            context: $context,
            resource: $deployment,
            permissions: [self::CONNECT_PERMISSION],
            tokenType: self::TOKEN_TYPE,
            expiresAt: $expiresAt,
            extraClaims: [
                'console_kind' => $consoleKind->value,
                'deployment_id' => $deployment->resourceId(),
            ],
        );

        $grant = new ConsoleAccessGrant(
            grantId: $jwtIssue->grant->resourceId(),
            jti: $jwtIssue->jti,
            tenantId: $context->activeTenantId,
            deploymentId: $deployment->resourceId(),
            consoleKind: $consoleKind,
            expiresAt: $jwtIssue->expiresAt,
        );

        return new ConsoleAccessGrantIssue(
            grant: $grant,
            jwt: $jwtIssue->jwt,
            kid: $jwtIssue->kid,
        );
    }

    private function grantTtlSeconds(): int
    {
        $ttl = config('racklab.console.grant_ttl_seconds', 300);

        return is_int($ttl) && $ttl > 0 ? $ttl : 300;
    }

    private function auditDenied(
        User $issuer,
        TenantContext $context,
        Deployment $deployment,
        ConsoleKind $consoleKind,
        ?string $reason,
    ): void {
        $this->auditEvents->append([
            'event_type' => 'console.access.denied',
            'action' => 'issue_grant',
            'result' => 'denied',
            'actor_type' => 'user',
            'actor_id' => (string) $issuer->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => $deployment->resourceType(),
            'resource_id' => $deployment->resourceId(),
            'resource_tenant' => $deployment->tenantId(),
            'target_tenant_set' => [$context->activeTenantId, $deployment->tenantId()],
            'effective_permissions' => [self::CONNECT_PERMISSION],
            'metadata' => [
                'console_kind' => $consoleKind->value,
                'reason' => $reason ?? 'permission_not_granted',
            ],
        ]);
    }
}
