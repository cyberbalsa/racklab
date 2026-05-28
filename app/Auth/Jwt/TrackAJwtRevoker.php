<?php

declare(strict_types=1);

namespace App\Auth\Jwt;

use App\Audit\AuditEventWriter;
use App\Models\JwtRevocation;
use App\Models\TokenGrant;
use App\Models\User;

final readonly class TrackAJwtRevoker
{
    public function __construct(private AuditEventWriter $auditEvents) {}

    public function revoke(string $jti, ?string $tenantId, ?User $revokedBy, string $reason): void
    {
        /** @var JwtRevocation $revocation */
        $revocation = JwtRevocation::query()->firstOrCreate(
            ['jti' => $jti],
            [
                'tenant_id' => $tenantId,
                'revoked_by_id' => $revokedBy?->id,
                'reason' => $reason,
                'revoked_at' => now(),
            ],
        );

        /** @var TokenGrant|null $grant */
        $grant = TokenGrant::query()->where('jti', $jti)->first();

        if ($grant instanceof TokenGrant && $grant->revoked_at === null) {
            $grant->forceFill([
                'revoked_at' => $revocation->revoked_at,
                'revoked_by_id' => $revokedBy?->id,
            ])->save();
        }

        $this->auditEvents->append([
            'event_type' => 'token.grant',
            'action' => 'revoke',
            'result' => 'allowed',
            'actor_type' => $revokedBy instanceof User ? 'user' : 'system',
            'actor_id' => $revokedBy instanceof User ? (string) $revokedBy->id : 'system',
            'actor_tenant' => $tenantId,
            'resource_type' => $grant instanceof TokenGrant ? $grant->resourceType() : 'token_grant',
            'resource_id' => $grant instanceof TokenGrant ? $grant->resourceId() : null,
            'resource_tenant' => $tenantId,
            'target_tenant_set' => $tenantId === null ? [] : [$tenantId],
            'effective_permissions' => $grant instanceof TokenGrant ? $grant->abilities : [],
            'metadata' => [
                'track' => 'jwt',
                'jti' => $jti,
                'reason' => $reason,
            ],
        ]);
    }
}
