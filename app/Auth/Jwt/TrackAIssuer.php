<?php

declare(strict_types=1);

namespace App\Auth\Jwt;

use App\Audit\AuditEventWriter;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Models\Project;
use App\Models\TokenGrant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class TrackAIssuer
{
    public function __construct(
        private AccessResolver $accessResolver,
        private AuditEventWriter $auditEvents,
        private SigningKeyRepository $signingKeys,
    ) {}

    /**
     * @param  list<string>  $permissions
     */
    public function issue(
        User $issuer,
        TenantContext $context,
        Project $project,
        array $permissions,
        string $tokenType,
        ?CarbonImmutable $expiresAt = null,
    ): TrackAJwtIssue {
        $permissions = $this->normalizePermissions($permissions);
        $actor = new ActorIdentity((string) $issuer->id);

        foreach ($permissions as $permission) {
            $decision = $this->accessResolver->permitted(
                $actor,
                new Permission($permission),
                $project,
                $context,
            );

            if (! $decision->allowed) {
                $this->auditDenied($issuer, $context, $project, $permissions, 'permission_not_granted');

                throw ValidationException::withMessages([
                    'permissions' => sprintf('The JWT permission [%s] exceeds the issuer permissions for this project.', $permission),
                ]);
            }
        }

        if ($permissions === []) {
            throw ValidationException::withMessages(['permissions' => 'A Track A JWT requires at least one permission.']);
        }

        return DB::transaction(function () use ($issuer, $context, $project, $permissions, $tokenType, $expiresAt): TrackAJwtIssue {
            $now = CarbonImmutable::now();
            $ttl = $this->jwtTtlSeconds();
            $expiresAt ??= $now->addSeconds($ttl > 0 ? $ttl : 300);
            $jti = (string) Str::ulid();
            $key = $this->signingKeys->current();

            if (! is_string($key->private_key_pem) || trim($key->private_key_pem) === '') {
                throw new AuthorizationException('Current JWT signing key cannot sign tokens.');
            }

            /** @var TokenGrant $grant */
            $grant = TokenGrant::query()->create([
                'tenant_id' => $context->activeTenantId,
                'owner_user_id' => $issuer->id,
                'created_by_id' => $issuer->id,
                'jti' => $jti,
                'name' => sprintf('%s token', $tokenType),
                'track' => 'jwt',
                'scope_type' => RoleBindingScopeType::TenantLocal,
                'tenant_set' => [],
                'resource_type' => $project->resourceType(),
                'resource_id' => $project->resourceId(),
                'abilities' => $permissions,
                'allowed_ip_cidrs' => [],
                'expires_at' => $expiresAt,
            ]);

            $payload = [
                'iss' => $this->jwtConfigString('issuer'),
                'aud' => $this->jwtConfigString('audience'),
                'sub' => (string) $issuer->id,
                'exp' => $expiresAt->getTimestamp(),
                'iat' => $now->getTimestamp(),
                'nbf' => $now->getTimestamp(),
                'jti' => $jti,
                'grant_id' => $grant->resourceId(),
                'tenant_id' => $context->activeTenantId,
                'scope_type' => RoleBindingScopeType::TenantLocal->value,
                'resource_type' => $project->resourceType(),
                'resource_id' => $project->resourceId(),
                'permissions' => $permissions,
                'token_type' => $tokenType,
            ];

            $jwt = JWT::encode($payload, $key->private_key_pem, 'RS256', $key->kid);

            $this->auditEvents->append([
                'event_type' => 'token.grant',
                'action' => 'create',
                'result' => 'allowed',
                'actor_type' => 'user',
                'actor_id' => (string) $issuer->id,
                'actor_tenant' => $context->activeTenantId,
                'resource_type' => $grant->resourceType(),
                'resource_id' => $grant->resourceId(),
                'resource_tenant' => $context->activeTenantId,
                'target_tenant_set' => [$context->activeTenantId],
                'effective_permissions' => $permissions,
                'metadata' => [
                    'track' => 'jwt',
                    'jti' => $jti,
                    'token_type' => $tokenType,
                ],
            ]);

            return new TrackAJwtIssue($grant, $jwt, $jti, $key->kid, $expiresAt);
        });
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function normalizePermissions(array $permissions): array
    {
        $normalized = [];

        foreach ($permissions as $permission) {
            $permission = trim($permission);

            if ($permission !== '') {
                $normalized[] = $permission;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function jwtTtlSeconds(): int
    {
        $ttl = config('racklab.jwt.ttl_seconds', 300);

        return is_int($ttl) ? $ttl : 300;
    }

    private function jwtConfigString(string $key): string
    {
        $value = config(sprintf('racklab.jwt.%s', $key));

        return is_string($value) && trim($value) !== '' ? $value : 'racklab';
    }

    /**
     * @param  list<string>  $permissions
     */
    private function auditDenied(
        User $issuer,
        TenantContext $context,
        Project $project,
        array $permissions,
        string $reason,
    ): void {
        $this->auditEvents->append([
            'event_type' => 'token.grant',
            'action' => 'create',
            'result' => 'denied',
            'actor_type' => 'user',
            'actor_id' => (string) $issuer->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => 'token_grant',
            'resource_id' => null,
            'resource_tenant' => $context->activeTenantId,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => $permissions,
            'metadata' => [
                'track' => 'jwt',
                'reason' => $reason,
                'resource_type' => $project->resourceType(),
                'resource_id' => $project->resourceId(),
            ],
        ]);
    }
}
